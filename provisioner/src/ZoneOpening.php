<?php

declare(strict_types=1);

namespace Provisioner;

use Provisioner\Exception\DownloadFailedException;
use Provisioner\Exception\ImportFailedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The source sequence that opens one zone (ADR-049 §1): OSM, DataTourisme and OpenAgenda,
 * each its own step with its own download, staging schema and promotion.
 *
 * Each step is attempted independently: one source failing must not abort the others, so a
 * single bad refresh degrades only its own dataset (ADR-041). A source left unconfigured
 * (null importer) is skipped with a warning, never a failure.
 */
final readonly class ZoneOpening
{
    /**
     * @param string $zonesDir root of the per-zone report directory: `<zonesDir>/<zone>/rejected.tsv` (#886)
     */
    public function __construct(
        private OsmDataDownloader $downloader,
        private PostgisImporter $postgisImporter,
        private RoutingPerimeter $routingPerimeter,
        private ?DataTourismeImporter $dataTourismeImporter,
        private ?OpenAgendaImporter $openAgendaImporter,
        private ZoneOpeningReport $report,
        private ProvisionerLog $log,
        private string $filteredPbf,
        private string $dataTourismeDir,
        private string $openAgendaDir,
        private string $zonesDir,
    ) {
    }

    /**
     * @param array{name: string, slug: string, size: string, country: string} $zone
     *
     * @return array<string, int> source => Command exit code
     */
    public function open(SymfonyStyle $io, array $zone, bool $dryRun): array
    {
        $outcomes = [];

        // DataTourisme is staged *before* the OSM import and promoted *after* it (#885).
        // The flux is the curated source — not one of its 124 240 accommodations has an
        // empty name — so the OSM gate must be able to borrow a name from it, which means
        // having it in hand before deciding what to reject. Promotion still comes last,
        // because it clips to the zone geometry only the OSM import produces. A staging
        // failure degrades that source alone: OSM then runs with one fewer resolver step.
        // The same instance serves both halves: the unmapped-subtype count is accumulated
        // by the mapper while staging and read after promoting.
        // Pinned once for the whole run: the events purge boundary is a calendar date
        // computed in Europe/Paris, never `now()` in SQL, so it does not drift with the
        // server timezone (ADR-051 §4, EventsPromotion).
        $today = DataTourismeImporter::today();

        // What the zone-opening report is scoped to: a source this run skipped or failed
        // must not show the figures of an earlier promotion.
        $startedAt = new \DateTimeImmutable();

        $curated = $dryRun ? null : $this->dataTourismeImporter;
        if (!$dryRun && !$curated instanceof DataTourismeImporter) {
            // OSM is the primary source and must still provision (ADR-041 continue-on-error),
            // with one fewer resolver step.
            $io->warning('DataTourisme import skipped: DATATOURISME_FLUX_ID and DATATOURISME_APP_KEY are not set.');
        }

        $curatedTable = null;
        $curatedOutcome = Command::SUCCESS;

        if ($curated instanceof DataTourismeImporter) {
            [$curatedOutcome, $curatedTable] = $this->stageDataTourisme($io, $curated, $zone['slug']);
        }

        $outcomes['osm'] = $this->runOsm($io, $zone, $dryRun, $curatedTable);

        // OpenAgenda events (ADR-051, #984). Events-only, so it does not interleave with
        // the OSM name gate: it runs entirely after OSM, whose geometry it clips against.
        // Independent of the other sources' outcomes (ADR-041), and promoted *before* the
        // DataTourisme finish so the DataTourisme metadata refresh counts its events in the
        // live totals. Failing here degrades events alone.
        if (!$dryRun) {
            $outcomes['openagenda'] = $this->runOpenAgenda($io, $zone['slug'], $today);
        }

        // Deliberately not gated on $outcomes['osm']: a failed OSM refresh must not block a
        // DataTourisme refresh for a zone that is already open (ADR-041). The real
        // precondition — a registry geometry to clip against — is checked by finish()
        // itself, which skips rather than promoting nothing and calling it a success.
        if ($curated instanceof DataTourismeImporter && Command::SUCCESS === $curatedOutcome) {
            $curatedOutcome = $this->finishDataTourisme($io, $curated, $zone['slug'], $today);
        }

        if (!$dryRun) {
            $outcomes['datatourisme'] = $curatedOutcome;
            $this->report->promotion($io, $zone['slug'], $startedAt);
        }

        return $outcomes;
    }

    /**
     * Downloads and stages the flux, without promoting it.
     *
     * @return array{0: int, 1: string|null} exit code, and the staged accommodation table the
     *                                       OSM gate can match against (null when there is none)
     */
    private function stageDataTourisme(SymfonyStyle $io, DataTourismeImporter $importer, string $zoneSlug): array
    {
        if (!is_dir($this->dataTourismeDir) && !mkdir($this->dataTourismeDir, 0o755, true) && !is_dir($this->dataTourismeDir)) {
            $io->error(\sprintf('Cannot create DataTourisme work directory "%s"', $this->dataTourismeDir));

            return [Command::FAILURE, null];
        }

        $io->section('Staging the DataTourisme flux');

        try {
            $staging = $importer->stage($this->dataTourismeDir, $zoneSlug);
        } catch (ImportFailedException $importFailedException) {
            $this->log->fail($io, $importFailedException->getMessage());

            return [Command::FAILURE, null];
        }

        $io->writeln('  Staged; the OSM gate can now borrow names from it.');

        return [Command::SUCCESS, $staging.'.accommodations'];
    }

    private function finishDataTourisme(SymfonyStyle $io, DataTourismeImporter $importer, string $zoneSlug, string $today): int
    {
        $io->section('Promoting DataTourisme into PostGIS');

        try {
            $promoted = $importer->finish($this->dataTourismeDir, $zoneSlug, $today, $this->zonesDir);
        } catch (ImportFailedException $importFailedException) {
            $this->log->fail($io, $importFailedException->getMessage());

            return Command::FAILURE;
        }

        if (!$promoted) {
            // No registry geometry to clip against, so there was nothing to promote into. A
            // skip, not a failure: the flux is national and the next opening of this zone
            // re-downloads it anyway.
            $message = 'DataTourisme promotion skipped: the zone has no registry geometry to clip against, so the OSM step did not complete.';
            $io->warning($message);
            $this->log->line('INFO', $message);

            return Command::SUCCESS;
        }

        $unmapped = $importer->unmappedAccommodationCount();
        $io->success(\sprintf('DataTourisme import complete (%d accommodations skipped: unmapped subtype).', $unmapped));
        $this->log->line('INFO', \sprintf('datatourisme accommodations skipped (unmapped subtype) -> %d', $unmapped));

        return Command::SUCCESS;
    }

    /**
     * Downloads, stages and promotes the OpenAgenda events for the zone. Skipped
     * gracefully when OpenAgenda is not configured — OSM and DataTourisme still
     * provision (ADR-041 continue-on-error).
     */
    private function runOpenAgenda(SymfonyStyle $io, string $zoneSlug, string $today): int
    {
        $importer = $this->openAgendaImporter;
        if (!$importer instanceof OpenAgendaImporter) {
            $io->warning('OpenAgenda import skipped: OPENAGENDA_DATASET is not set.');

            return Command::SUCCESS;
        }

        if (!is_dir($this->openAgendaDir) && !mkdir($this->openAgendaDir, 0o755, true) && !is_dir($this->openAgendaDir)) {
            $io->error(\sprintf('Cannot create OpenAgenda work directory "%s"', $this->openAgendaDir));

            return Command::FAILURE;
        }

        $io->section('Importing OpenAgenda events');

        try {
            $promoted = $importer->run($this->openAgendaDir, $zoneSlug, $today);
        } catch (ImportFailedException $importFailedException) {
            $this->log->fail($io, $importFailedException->getMessage());

            return Command::FAILURE;
        }

        if (!$promoted) {
            // No registry geometry to clip against: the OSM step did not complete. A skip,
            // not a failure — the export is national and the next opening re-downloads it.
            $message = 'OpenAgenda import skipped: the zone has no registry geometry to clip against, so the OSM step did not complete.';
            $io->warning($message);
            $this->log->line('INFO', $message);

            return Command::SUCCESS;
        }

        $io->success('OpenAgenda events imported.');
        $this->log->line('INFO', \sprintf('openagenda events imported for zone %s', $zoneSlug));

        return Command::SUCCESS;
    }

    /**
     * @param array{name: string, slug: string, size: string, country: string} $zone
     */
    private function runOsm(SymfonyStyle $io, array $zone, bool $dryRun, ?string $curatedTable = null): int
    {
        $io->section(\sprintf('Opening zone %s (%s, %s)', $zone['name'], $zone['slug'], $zone['size']));

        $targetPath = $this->downloader->targetPath($zone['slug']);

        if ($dryRun) {
            $io->writeln(\sprintf('  Would download %s', GeofabrikRegionRegistry::downloadUrl($zone['slug'])));
            $io->writeln(\sprintf('  Would import %s into %s', $targetPath, PostgisImporter::stagingSchema($zone['slug'])));
            $io->note('Dry run — nothing downloaded, nothing imported.');

            return Command::SUCCESS;
        }

        // Record the observed routing perimeter so /api/health can assert containment
        // from the database alone. Best-effort by design: it is an observation, and
        // failing to write it must not fail an otherwise valid import.
        try {
            $this->routingPerimeter->record();
        } catch (ImportFailedException $importFailedException) {
            $io->warning(\sprintf('Could not record the routing perimeter: %s', $importFailedException->getMessage()));
        }

        // The extract is always re-downloaded: a zone is opened deliberately, and the
        // point of opening it again is to pick up what OSM has since gained.
        $io->write(\sprintf('  Downloading %s... ', $zone['slug']));

        try {
            $this->downloader->download($zone['slug']);
        } catch (DownloadFailedException $downloadFailedException) {
            $io->newLine();
            $this->log->fail($io, $downloadFailedException->getMessage());

            return Command::FAILURE;
        }

        $io->writeln("\u{2713}");
        $io->write('  Importing Tier-1 features into PostGIS... ');

        try {
            $gate = $this->postgisImporter->run($zone['slug'], $zone['name'], $zone['country'], $targetPath, $this->filteredPbf, $curatedTable, $this->zonesDir);
        } catch (ImportFailedException $importFailedException) {
            $io->newLine();
            $this->log->fail($io, $importFailedException->getMessage());

            return Command::FAILURE;
        }

        $io->writeln("\u{2713}");
        $this->report->gate($io, $zone['slug'], $gate);
        $io->success(\sprintf('Zone %s is open. Every other zone was left untouched.', $zone['name']));

        return Command::SUCCESS;
    }
}
