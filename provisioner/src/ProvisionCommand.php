<?php

declare(strict_types=1);

namespace Provisioner;

use Provisioner\Exception\DownloadFailedException;
use Provisioner\Exception\ImportFailedException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
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
final class ProvisionCommand extends Command
{
    private const string DEFAULT_REGIONS_DIR = '/data/regions';

    private const string DEFAULT_FILTERED_PBF = '/data/tier1-filtered.osm.pbf';

    private const string DEFAULT_DATATOURISME_DIR = '/data/datatourisme';

    private const string DEFAULT_LOCK_FILE = '/data/provision.lock';

    private const string DEFAULT_LOG_FILE = '/data/provisioner.log';

    /**
     * @var resource|null held for the whole command so the flock is released only
     *                    when the process ends (incl. a crash: the OS drops it)
     */
    private $lockHandle;

    private readonly OsmDataDownloader $downloader;

    private readonly PostgisImporter $postgisImporter;

    private readonly RoutingPerimeter $routingPerimeter;

    private readonly PromotionReport $promotionReport;

    public function __construct(
        private readonly string $regionsDir = self::DEFAULT_REGIONS_DIR,
        ?OsmDataDownloader $downloader = null,
        private readonly string $filteredPbf = self::DEFAULT_FILTERED_PBF,
        ?PostgisImporter $postgisImporter = null,
        private readonly string $dataTourismeDir = self::DEFAULT_DATATOURISME_DIR,
        // Built lazily in runDataTourisme() from DATATOURISME_* env when not injected.
        private readonly ?DataTourismeImporter $dataTourismeImporter = null,
        private readonly string $lockFile = self::DEFAULT_LOCK_FILE,
        private readonly string $logFile = self::DEFAULT_LOG_FILE,
        ?RoutingPerimeter $routingPerimeter = null,
        ?PromotionReport $promotionReport = null,
    ) {
        parent::__construct();

        $this->downloader = $downloader ?? new OsmDataDownloader(regionsDir: $this->regionsDir);
        $this->postgisImporter = $postgisImporter ?? new PostgisImporter(
            flexStylePath: \dirname(__DIR__).'/osm2pgsql/tier1.lua',
        );
        $this->routingPerimeter = $routingPerimeter ?? new RoutingPerimeter();
        $this->promotionReport = $promotionReport ?? new PromotionReport();
    }

    protected function configure(): void
    {
        // Optional at the console level, required in fact: validating it here buys the
        // list of zones and the routing hint in the error, which "Not enough arguments"
        // cannot give.
        $this->addArgument('zone', InputArgument::OPTIONAL, 'Geofabrik slug or name of the zone to open (e.g. bretagne)');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be downloaded and imported without executing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('OSM Reference Zone Provisioner');

        $zoneArgument = $input->getArgument('zone');
        $zone = \is_string($zoneArgument) ? GeofabrikRegionRegistry::resolve($zoneArgument) : null;

        if (null === $zone) {
            $this->fail($io, \sprintf(
                'A zone is required: `make provision <zone>` opens exactly one (e.g. `make provision bretagne`).%s%s',
                \is_string($zoneArgument) && '' !== trim($zoneArgument) ? \sprintf(' "%s" is not a known zone.', $zoneArgument) : '',
                \sprintf("\nKnown zones: %s", implode(', ', GeofabrikRegionRegistry::slugs())),
            ));

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');

        if (!$this->assertRoutingCovers($io, $zone['country'], $zone['name'])) {
            return Command::FAILURE;
        }

        // Serialise concurrent runs (cron + manual overlap): two provisioners writing the
        // same zone would race on its staging schema (ADR-041).
        if (!$this->acquireLock($io)) {
            return Command::FAILURE;
        }

        try {
            // Each reference source runs as its own step (own download, staging schema and
            // promotion) and is attempted independently: one source failing must not abort
            // the others, so a single bad refresh degrades only its own dataset (ADR-041).
            // Outcomes are aggregated into the final exit code.
            $outcomes = [];

            $outcomes['osm'] = $this->runOsm($io, $zone, $dryRun);

            if (!$dryRun) {
                $outcomes['datatourisme'] = $this->runDataTourisme($io, $zone['slug']);
                $this->reportPromotion($io, $zone['slug']);
            }

            $this->summarize($io, $outcomes);

            return \in_array(Command::FAILURE, $outcomes, true) ? Command::FAILURE : Command::SUCCESS;
        } finally {
            $this->releaseLock();
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
    private function assertRoutingCovers(SymfonyStyle $io, string $country, string $zoneName): bool
    {
        if (!$this->routingPerimeter->isObservable()) {
            $io->warning('The routing volume is not mounted, so the routing perimeter cannot be checked. Opening the zone anyway; verify that the graph covers it.');

            return true;
        }

        if ($this->routingPerimeter->covers($country)) {
            return true;
        }

        $built = $this->routingPerimeter->slugs();
        $this->fail($io, \sprintf(
            '%s is in "%s", which the routing graph does not cover, so a trip there could not be routed. Build it first with `make routing-build %s`, then provision again. Routing graph currently built from: %s.',
            $zoneName,
            $country,
            $country,
            [] === $built ? 'nothing' : implode(', ', $built),
        ));

        return false;
    }

    /**
     * Acquires an exclusive, non-blocking file lock held for the whole run. The
     * OS releases it when the process ends — including a crash — so a killed run
     * never leaves a stale lock behind.
     */
    private function acquireLock(SymfonyStyle $io): bool
    {
        $handle = @fopen($this->lockFile, 'c');
        if (false === $handle) {
            // No lock file location (e.g. /data not mounted): proceed rather than
            // block provisioning on an inability to lock.
            $io->warning(\sprintf('Cannot open lock file "%s"; proceeding without a concurrency lock.', $this->lockFile));

            return true;
        }

        if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
            fclose($handle);
            $message = 'Another provisioning run is already in progress; aborting.';
            $io->error($message);
            $this->logLine('ERROR', $message);

            return false;
        }

        $this->lockHandle = $handle;

        return true;
    }

    private function releaseLock(): void
    {
        if (\is_resource($this->lockHandle)) {
            flock($this->lockHandle, \LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    /**
     * @param array<string, int> $outcomes source label => Command exit code
     */
    private function summarize(SymfonyStyle $io, array $outcomes): void
    {
        $io->section('Provisioning summary');
        foreach ($outcomes as $source => $code) {
            $ok = Command::SUCCESS === $code;
            $io->writeln(\sprintf('  %s %s', $ok ? "\u{2713}" : "\u{2717}", $source));
            $this->logLine($ok ? 'INFO' : 'ERROR', \sprintf('source %s -> %s', $source, $ok ? 'ok' : 'failed'));
        }
    }

    /**
     * The zone-opening report: what each source offered and what was actually new. "0 new
     * entries" on a re-open is the evidence that the identity anti-join works, so it is
     * stated rather than left to be inferred from silence.
     */
    private function reportPromotion(SymfonyStyle $io, string $zoneSlug): void
    {
        $rows = $this->promotionReport->forZone($zoneSlug, \dirname($this->filteredPbf));
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
        $this->logLine('INFO', \sprintf('zone %s -> %d new entries', $zoneSlug, $added));
    }

    /**
     * Reports a failure both to the console and to the persistent log file, so
     * the detailed cause (command + stderr) survives for later diagnosis even
     * when the container logs are gone (ADR-041).
     */
    private function fail(SymfonyStyle $io, string $message): void
    {
        $io->error($message);
        $this->logLine('ERROR', $message);
    }

    private function logLine(string $level, string $message): void
    {
        $line = \sprintf("[%s] [%s] %s\n", new \DateTimeImmutable()->format('Y-m-d H:i:s'), $level, $message);
        // Best-effort: never let logging failure mask the real outcome.
        @file_put_contents($this->logFile, $line, \FILE_APPEND);
    }

    private function runDataTourisme(SymfonyStyle $io, string $zoneSlug): int
    {
        $importer = $this->dataTourismeImporter;
        if (!$importer instanceof DataTourismeImporter) {
            $fluxId = getenv('DATATOURISME_FLUX_ID') ?: '';
            $appKey = getenv('DATATOURISME_APP_KEY') ?: '';
            if ('' === $fluxId || '' === $appKey) {
                // Skip gracefully when DataTourisme is not configured: OSM is the
                // primary source and must still provision (ADR-041 continue-on-error).
                $io->warning('DataTourisme import skipped: DATATOURISME_FLUX_ID and DATATOURISME_APP_KEY are not set.');

                return Command::SUCCESS;
            }

            $importer = new DataTourismeImporter(
                \sprintf('https://diffuseur.datatourisme.fr/webservice/%s/%s', $fluxId, $appKey),
            );
        }

        if (!is_dir($this->dataTourismeDir) && !mkdir($this->dataTourismeDir, 0o755, true) && !is_dir($this->dataTourismeDir)) {
            $io->error(\sprintf('Cannot create DataTourisme work directory "%s"', $this->dataTourismeDir));

            return Command::FAILURE;
        }

        $io->section('Importing DataTourisme into PostGIS');

        try {
            $importer->run($this->dataTourismeDir, $zoneSlug);
        } catch (ImportFailedException $importFailedException) {
            $this->fail($io, $importFailedException->getMessage());

            return Command::FAILURE;
        }

        $unmapped = $importer->unmappedAccommodationCount();
        $io->success(\sprintf('DataTourisme import complete (%d accommodations skipped: unmapped subtype).', $unmapped));
        $this->logLine('INFO', \sprintf('datatourisme accommodations skipped (unmapped subtype) -> %d', $unmapped));

        return Command::SUCCESS;
    }

    /**
     * @param array{name: string, slug: string, size: string, country: string} $zone
     */
    private function runOsm(SymfonyStyle $io, array $zone, bool $dryRun): int
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
            $this->fail($io, $downloadFailedException->getMessage());

            return Command::FAILURE;
        }

        $io->writeln("\u{2713}");
        $io->write('  Importing Tier-1 features into PostGIS... ');

        try {
            $this->postgisImporter->run($zone['slug'], $zone['name'], $zone['country'], $targetPath, $this->filteredPbf);
        } catch (ImportFailedException $importFailedException) {
            $io->newLine();
            $this->fail($io, $importFailedException->getMessage());

            return Command::FAILURE;
        }

        $io->writeln("\u{2713}");
        $io->success(\sprintf('Zone %s is open. Every other zone was left untouched.', $zone['name']));

        return Command::SUCCESS;
    }
}
