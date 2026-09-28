<?php

declare(strict_types=1);

namespace Provisioner;

use Provisioner\Exception\DownloadFailedException;
use Provisioner\Exception\ImportFailedException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Opens one reference zone: `provision <zone>` (ADR-049 §1).
 *
 * There used to be no such operation. `RegionSelectionStore` kept a **cumulative** list
 * of slugs in `regions.json` and every run re-downloaded, re-merged and re-imported all
 * of them, so opening 13 regions one at a time cost 13 full re-imports of a growing
 * dataset. The zone is now a mandatory argument, the selection file and the interactive
 * selector are gone, and the source of truth for what is open is `osm.zones` in the
 * database.
 *
 * Two consequences visible here:
 *
 * - **No merge.** One zone per run means one extract to filter, so `osmium merge`
 *   disappeared along with the reference staging PBF it produced.
 * - **The containment invariant is checked before anything is downloaded.** ADR-049 §6
 *   requires the routing perimeter to encompass the reference perimeter; nothing
 *   maintains that, so refusing a zone the graph does not cover — with an actionable
 *   message — is the whole user experience of the invariant.
 */
#[AsCommand(
    name: 'provision',
    description: 'Open one OSM reference zone: download its extract and promote it into the PostGIS reference index',
)]
final readonly class ProvisionCommand
{
    private const string DEFAULT_REGIONS_DIR = '/data/regions';

    private const string DEFAULT_FILTERED_PBF = '/data/tier1-filtered.osm.pbf';

    private const string DEFAULT_DATATOURISME_DIR = '/data/datatourisme';

    private const string DEFAULT_OPENAGENDA_DIR = '/data/openagenda';

    /**
     * Root of the per-zone report directory: `<zonesDir>/<zone>/rejected.tsv` (#886). Under
     * `/data`, which is bind-mounted, so the operator picks the file up on the host — and
     * `.docker/osm/data/*` is gitignored, so no data file can reach the repository.
     */
    private const string DEFAULT_ZONES_DIR = '/data/zones';

    private const string DEFAULT_LOCK_FILE = '/data/provision.lock';

    private const string DEFAULT_LOG_FILE = '/data/provisioner.log';

    private ProvisionerLog $log;

    private RunLock $lock;

    private OsmDataDownloader $downloader;

    private PostgisImporter $postgisImporter;

    private RoutingPerimeter $routingPerimeter;

    private PromotionReport $promotionReport;

    public function __construct(
        private string $regionsDir = self::DEFAULT_REGIONS_DIR,
        ?OsmDataDownloader $downloader = null,
        private string $filteredPbf = self::DEFAULT_FILTERED_PBF,
        ?PostgisImporter $postgisImporter = null,
        private string $dataTourismeDir = self::DEFAULT_DATATOURISME_DIR,
        // Built lazily in runDataTourisme() from DATATOURISME_* env when not injected.
        private ?DataTourismeImporter $dataTourismeImporter = null,
        private string $openAgendaDir = self::DEFAULT_OPENAGENDA_DIR,
        // Built lazily in resolveOpenAgendaImporter() from OPENAGENDA_* env when not injected.
        private ?OpenAgendaImporter $openAgendaImporter = null,
        private string $zonesDir = self::DEFAULT_ZONES_DIR,
        private string $lockFile = self::DEFAULT_LOCK_FILE,
        private string $logFile = self::DEFAULT_LOG_FILE,
        ?RoutingPerimeter $routingPerimeter = null,
        ?PromotionReport $promotionReport = null,
    ) {
        $this->log = new ProvisionerLog($this->logFile);
        $this->lock = new RunLock($this->lockFile, $this->log);

        $this->downloader = $downloader ?? new OsmDataDownloader(regionsDir: $this->regionsDir);
        $this->postgisImporter = $postgisImporter ?? new PostgisImporter(
            flexStylePath: \dirname(__DIR__).'/osm2pgsql/tier1.lua',
        );
        $this->routingPerimeter = $routingPerimeter ?? new RoutingPerimeter();
        $this->promotionReport = $promotionReport ?? new PromotionReport();
    }

    public function __invoke(
        SymfonyStyle $io,
        // Optional at the console level, required in fact: validating it here buys the
        // list of zones and the routing hint in the error, which "Not enough arguments"
        // cannot give.
        #[Argument('Geofabrik slug or name of the zone to open (e.g. bretagne)', name: 'zone')]
        ?string $zoneArgument = null,
        #[Option('Show what would be downloaded and imported without executing')]
        bool $dryRun = false,
        // Local development only, and named so that using it in production reads as a
        // mistake. Without it, a dev machine cannot open any zone without first building the
        // national routing graph — hours and ~30 GB — just to work on accommodations. The
        // alternative an operator would otherwise improvise, dropping a fake extract into the
        // routing volume, is worse: `build-routing-graph.sh` skips downloading an extract that
        // is already present, so the next real build would silently build from the fake one.
        #[Option('Open the zone even if the routing graph does not cover it (local development; trips there cannot be routed)')]
        bool $allowUnroutedZone = false,
    ): int {
        $io->title('OSM Reference Zone Provisioner');

        $zone = null !== $zoneArgument ? GeofabrikRegionRegistry::resolve($zoneArgument) : null;

        if (null === $zone) {
            $this->log->fail($io, \sprintf(
                'A zone is required: `make provision <zone>` opens exactly one (e.g. `make provision bretagne`).%s',
                GeofabrikRegionRegistry::unresolvedZoneHint($zoneArgument),
            ));

            return Command::FAILURE;
        }

        if (!$this->assertRoutingCovers($io, $zone['country'], $zone['name'], $allowUnroutedZone)) {
            return Command::FAILURE;
        }

        // Serialise concurrent runs (cron + manual overlap): two provisioners writing the
        // same zone would race on its staging schema (ADR-041).
        if (!$this->lock->acquire($io)) {
            return Command::FAILURE;
        }

        try {
            // Each reference source runs as its own step (own download, staging schema and
            // promotion) and is attempted independently: one source failing must not abort
            // the others, so a single bad refresh degrades only its own dataset (ADR-041).
            // Outcomes are aggregated into the final exit code.
            $outcomes = [];

            // DataTourisme is staged *before* the OSM import and promoted *after* it (#885).
            // The flux is the curated source — not one of its 124 240 accommodations has an
            // empty name — so the OSM gate must be able to borrow a name from it, which means
            // having it in hand before deciding what to reject. Promotion still comes last,
            // because it clips to the zone geometry only the OSM import produces. A staging
            // failure degrades that source alone: OSM then runs with one fewer resolver step.
            // Resolved once and reused for both halves: the unmapped-subtype count is
            // accumulated by the mapper while staging and read after promoting, so a second
            // instance would report zero.
            // Pinned once for the whole run: the events purge boundary is a calendar date
            // computed in Europe/Paris, never `now()` in SQL, so it does not drift with the
            // server timezone (ADR-051 §4, EventsPromotion).
            $today = DataTourismeImporter::today();

            // What the zone-opening report is scoped to: a source this run skipped or failed
            // must not show the figures of an earlier promotion.
            $startedAt = new \DateTimeImmutable();

            $curated = $dryRun ? null : $this->resolveDataTourismeImporter($io);
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
                $this->reportPromotion($io, $zone['slug'], $startedAt);
            }

            $this->log->summarize($io, 'Provisioning summary', $outcomes);

            return \in_array(Command::FAILURE, $outcomes, true) ? Command::FAILURE : Command::SUCCESS;
        } finally {
            $this->lock->release();
        }
    }

    /**
     * Refuses a zone the routing graph does not cover (ADR-049 §6). An *observed* empty
     * perimeter refuses — that is a machine with no graph yet; a perimeter that cannot be
     * observed at all only warns, since a missing volume mount must not become a
     * provisioning outage.
     *
     * @param string $country Geofabrik country slug the zone belongs to
     */
    private function assertRoutingCovers(SymfonyStyle $io, string $country, string $zoneName, bool $allowUnrouted = false): bool
    {
        if ($allowUnrouted && !$this->routingPerimeter->covers($country)) {
            $io->warning(\sprintf(
                'Opening %s without checking the routing graph (--allow-unrouted-zone). Trips in this zone will not be routable; this is for local development only.',
                $zoneName,
            ));

            return true;
        }

        if (!$this->routingPerimeter->isObservable()) {
            $io->warning('The routing volume is not mounted, so the routing perimeter cannot be checked. Opening the zone anyway; verify that the graph covers it.');

            return true;
        }

        if ($this->routingPerimeter->covers($country)) {
            return true;
        }

        $built = $this->routingPerimeter->slugs();
        $this->log->fail($io, \sprintf(
            '%s is in "%s", which the routing graph does not cover, so a trip there could not be routed. Build it first with `make routing-build %s`, then provision again. Routing graph currently built from: %s.',
            $zoneName,
            $country,
            $country,
            [] === $built ? 'nothing' : implode(', ', $built),
        ));

        return false;
    }

    /**
     * The zone-opening report: what each source offered and what was actually new. "0 new
     * entries" on a re-open is the evidence that the identity anti-join works, so it is
     * stated rather than left to be inferred from silence.
     */
    private function reportPromotion(SymfonyStyle $io, string $zoneSlug, \DateTimeImmutable $startedAt): void
    {
        $rows = $this->promotionReport->forZone($zoneSlug, \dirname($this->filteredPbf), $startedAt);
        if ([] === $rows) {
            return;
        }

        $io->section(\sprintf('Zone opening report — %s', $zoneSlug));
        $io->table(
            ['source', 'table', 'candidates', 'new entries', 'already present'],
            array_map(
                static fn (array $row): array => [
                    $row['source'],
                    $row['table'],
                    (string) $row['candidates'],
                    (string) $row['inserted'],
                    (string) ($row['candidates'] - $row['inserted']),
                ],
                $rows,
            ),
        );

        $added = array_sum(array_column($rows, 'inserted'));
        $io->writeln(0 === $added
            ? '  0 new entries: the sources carry nothing this zone did not already hold.'
            : \sprintf('  %d new entries across %d tables.', $added, \count($rows)));
        $this->log->line('INFO', \sprintf('zone %s -> %d new entries', $zoneSlug, $added));
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

    /**
     * The configured importer, or null when DataTourisme is not set up — in which case OSM is
     * the primary source and must still provision (ADR-041 continue-on-error), with one fewer
     * resolver step.
     */
    private function resolveDataTourismeImporter(SymfonyStyle $io): ?DataTourismeImporter
    {
        if ($this->dataTourismeImporter instanceof DataTourismeImporter) {
            return $this->dataTourismeImporter;
        }

        $importer = EnvImporters::dataTourisme();
        if (!$importer instanceof DataTourismeImporter) {
            $io->warning('DataTourisme import skipped: DATATOURISME_FLUX_ID and DATATOURISME_APP_KEY are not set.');
        }

        return $importer;
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
        $importer = $this->resolveOpenAgendaImporter($io);
        if (!$importer instanceof OpenAgendaImporter) {
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
     * The configured importer, or null when OpenAgenda is not set up.
     */
    private function resolveOpenAgendaImporter(SymfonyStyle $io): ?OpenAgendaImporter
    {
        if ($this->openAgendaImporter instanceof OpenAgendaImporter) {
            return $this->openAgendaImporter;
        }

        $importer = EnvImporters::openAgenda();
        if (!$importer instanceof OpenAgendaImporter) {
            $io->warning('OpenAgenda import skipped: OPENAGENDA_DATASET is not set.');
        }

        return $importer;
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
        $this->reportGate($io, $zone['slug'], $gate);
        $io->success(\sprintf('Zone %s is open. Every other zone was left untouched.', $zone['name']));

        return Command::SUCCESS;
    }

    /**
     * What the completeness gate accepted and refused, and where to act on the refusals.
     *
     * The diagnostic line matters more than the numbers. A large `rejected.tsv` is **not** a
     * signal that more human work is needed — it is a signal that the resolvers are bad, the
     * tag projection first among them. Saying so here keeps that reading from being buried
     * under a long file, which is exactly what #886 asks for.
     *
     * @param array{resolved?: int, rejected?: int, matched?: int, ambiguous?: int, reasons?: array<string, int>} $gate
     */
    private function reportGate(SymfonyStyle $io, string $zoneSlug, array $gate): void
    {
        $resolved = $gate['resolved'] ?? 0;
        $rejected = $gate['rejected'] ?? 0;
        $matched = $gate['matched'] ?? 0;
        $ambiguous = $gate['ambiguous'] ?? 0;

        if (0 === $resolved && 0 === $rejected) {
            return;
        }

        $io->section('Completeness gate');
        $io->writeln(\sprintf('  %d name(s) resolved, of which %d from the curated flux.', $resolved, $matched));
        $io->writeln(\sprintf('  %d entry(ies) refused%s.', $rejected, $ambiguous > 0 ? \sprintf(', %d of them as ambiguous matches', $ambiguous) : ''));

        foreach ($gate['reasons'] ?? [] as $reason => $count) {
            $io->writeln(\sprintf('    - %s: %d', $reason, $count));
        }

        if ($rejected > 0) {
            $io->writeln(\sprintf('  Ranked by distance to the nearest cycle route in %s/%s/rejected.tsv.', $this->zonesDir, $zoneSlug));
            $this->log->line('INFO', \sprintf('zone %s gate -> %d resolved, %d refused', $zoneSlug, $resolved, $rejected));
        }

        if ($rejected > $resolved) {
            $io->warning('More entries were refused than resolved. Read that as the resolvers being weak, not as a backlog of manual work: the tag projection should absorb most of the volume, and a long rejected.tsv means it did not.');
        }
    }
}
