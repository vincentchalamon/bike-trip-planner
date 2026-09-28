<?php

declare(strict_types=1);

namespace Provisioner;

use Provisioner\Exception\ImportFailedException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * `events-refresh` — the temporal lifecycle of the events layer (ADR-051 §4).
 *
 * Events are the one reference layer that is not append-only: they are dated and
 * perishable, so they need a periodic re-import that adds what is upcoming and drops what
 * has passed. This is why they get their own command rather than living inside
 * `provision <zone>`, which opens a *place* dataset one zone at a time and never expires a
 * row (ADR-049). Here the whole feed is refreshed: for every open zone the national feeds
 * (DataTourisme + OpenAgenda) are re-downloaded, their events upserted, and passed events
 * purged — all through {@see EventsPromotion}.
 *
 * A dedicated command, not a resurrected `osm-cron`: ADR-033's cron container was removed
 * (superseded by ADR-036) for a Docker-socket it no longer needs. This writes only
 * `tourism.events` — no schema swap, no Valhalla restart, no socket — so it is safe to run
 * on a schedule (a weekly Coolify scheduled task; see docs/runbooks/events-refresh.md).
 *
 * Each source is refreshed independently (ADR-041 continue-on-error): a feed that fails to
 * download degrades only its own events, and a zone whose promotion fails does not abort
 * the others. The feed is downloaded once and clipped to each open zone in turn.
 */
#[AsCommand(
    name: 'events-refresh',
    description: 'Refresh the events layer for every open zone: re-import the feeds and purge past events',
)]
final readonly class EventsRefreshCommand
{
    private const string DEFAULT_DATATOURISME_DIR = '/data/datatourisme';

    private const string DEFAULT_OPENAGENDA_DIR = '/data/openagenda';

    private const string DEFAULT_WORK_DIR = '/data/events-refresh';

    private const string DEFAULT_LOCK_FILE = '/data/provision.lock';

    private const string DEFAULT_LOG_FILE = '/data/provisioner.log';

    private ProvisionerLog $log;

    private RunLock $lock;

    private ProcessRunner $processes;

    /**
     * @param (\Closure(list<string>): Process)|null $processFactory psql factory for zone discovery; defaults to a real {@see Process}
     * @param string|null                            $today          the purge boundary as `YYYY-MM-DD`; defaults to today in Europe/Paris
     */
    public function __construct(
        private string $dataTourismeDir = self::DEFAULT_DATATOURISME_DIR,
        private ?DataTourismeImporter $dataTourismeImporter = null,
        private string $openAgendaDir = self::DEFAULT_OPENAGENDA_DIR,
        private ?OpenAgendaImporter $openAgendaImporter = null,
        private string $workDir = self::DEFAULT_WORK_DIR,
        private string $lockFile = self::DEFAULT_LOCK_FILE,
        private string $logFile = self::DEFAULT_LOG_FILE,
        ?\Closure $processFactory = null,
        private ?string $today = null,
        private float $timeoutSeconds = 60.0,
    ) {
        $this->log = new ProvisionerLog($this->logFile);
        $this->lock = new RunLock($this->lockFile, $this->log);

        $this->processes = new ProcessRunner($processFactory, $this->timeoutSeconds);
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Refresh a single open zone instead of all of them', name: 'zone')]
        ?string $zoneOption = null,
        #[Option('List the open zones and the purge date without importing anything')]
        bool $dryRun = false,
    ): int {
        $io->title('Events refresh');

        $today = $this->today ?? DataTourismeImporter::today();
        $io->writeln(\sprintf('  Purge boundary: events ending before %s are dropped.', $today));

        if (!$this->lock->acquire($io)) {
            return Command::FAILURE;
        }

        try {
            $zones = $this->openZones($io);
            if (null === $zones) {
                return Command::FAILURE;
            }

            if (null !== $zoneOption && '' !== $zoneOption) {
                if (!\in_array($zoneOption, $zones, true)) {
                    $this->log->fail($io, \sprintf('Zone "%s" is not open. Open zones: %s.', $zoneOption, [] === $zones ? 'none' : implode(', ', $zones)));

                    return Command::FAILURE;
                }

                $zones = [$zoneOption];
            }

            if ([] === $zones) {
                $io->warning('No open zone to refresh. Open a zone first with `make provision <zone>`.');

                return Command::SUCCESS;
            }

            $io->writeln(\sprintf('  Zones: %s.', implode(', ', $zones)));

            if ($dryRun) {
                $io->note('Dry run — nothing downloaded, nothing written.');

                return Command::SUCCESS;
            }

            $outcomes = [];
            foreach ($this->sources($io) as [$source, $sourceWorkDir]) {
                $outcomes[$source->label()] = $this->refreshSource($io, $source, $sourceWorkDir, $zones, $today);
            }

            if ([] === $outcomes) {
                $io->warning('No events source is configured (DATATOURISME_* / OPENAGENDA_DATASET). Nothing to refresh.');

                return Command::SUCCESS;
            }

            $this->log->summarize($io, 'Events refresh summary', $outcomes);

            return \in_array(Command::FAILURE, $outcomes, true) ? Command::FAILURE : Command::SUCCESS;
        } finally {
            $this->lock->release();
        }
    }

    /**
     * Refreshes one source for every zone: download once, upsert-and-purge per zone. A
     * failed download degrades this source alone; a failed zone does not abort the rest
     * (ADR-041).
     *
     * @param list<string> $zones
     */
    private function refreshSource(SymfonyStyle $io, EventsRefreshSourceInterface $source, string $sourceWorkDir, array $zones, string $today): int
    {
        $io->section(\sprintf('Refreshing %s events', $source->label()));

        if (!is_dir($sourceWorkDir) && !mkdir($sourceWorkDir, 0o755, true) && !is_dir($sourceWorkDir)) {
            $this->log->fail($io, \sprintf('Cannot create work directory "%s"', $sourceWorkDir));

            return Command::FAILURE;
        }

        try {
            $staging = $source->stageEventsForRefresh($sourceWorkDir);
        } catch (ImportFailedException $importFailedException) {
            $this->log->fail($io, \sprintf('%s feed download/parse failed: %s', $source->label(), $importFailedException->getMessage()));

            return Command::FAILURE;
        }

        $outcome = Command::SUCCESS;
        foreach ($zones as $zone) {
            try {
                $source->promoteEventsForZone($staging, $zone, $today);
                $io->writeln(\sprintf('  %s %s', "\u{2713}", $zone));
                $this->log->line('INFO', \sprintf('%s events refreshed for zone %s', $source->label(), $zone));
            } catch (ImportFailedException $importFailedException) {
                $outcome = Command::FAILURE;
                $this->log->fail($io, \sprintf('%s zone %s failed: %s', $source->label(), $zone, $importFailedException->getMessage()));
            }
        }

        try {
            $source->dropRefreshStaging($staging);
        } catch (ImportFailedException $importFailedException) {
            // Best-effort cleanup: a leftover staging schema is dropped by the next run's
            // `DROP ... IF EXISTS`, so this must not turn a successful refresh into a failure.
            $io->warning(\sprintf('Could not drop %s staging schema: %s', $source->label(), $importFailedException->getMessage()));
        }

        return $outcome;
    }

    /**
     * The configured sources, each paired with its work directory. Same env resolution as
     * {@see ProvisionCommand}: a source with no credentials is simply absent, never a
     * failure (ADR-041).
     *
     * @return list<array{0: EventsRefreshSourceInterface, 1: string}>
     */
    private function sources(SymfonyStyle $io): array
    {
        $sources = [];

        $dataTourisme = $this->resolveDataTourismeImporter($io);
        if ($dataTourisme instanceof DataTourismeImporter) {
            $sources[] = [$dataTourisme, $this->dataTourismeDir];
        }

        $openAgenda = $this->resolveOpenAgendaImporter($io);
        if ($openAgenda instanceof OpenAgendaImporter) {
            $sources[] = [$openAgenda, $this->openAgendaDir];
        }

        return $sources;
    }

    private function resolveDataTourismeImporter(SymfonyStyle $io): ?DataTourismeImporter
    {
        if ($this->dataTourismeImporter instanceof DataTourismeImporter) {
            return $this->dataTourismeImporter;
        }

        $importer = EnvImporters::dataTourisme();
        if (!$importer instanceof DataTourismeImporter) {
            $io->warning('DataTourisme refresh skipped: DATATOURISME_FLUX_ID and DATATOURISME_APP_KEY are not set.');
        }

        return $importer;
    }

    private function resolveOpenAgendaImporter(SymfonyStyle $io): ?OpenAgendaImporter
    {
        if ($this->openAgendaImporter instanceof OpenAgendaImporter) {
            return $this->openAgendaImporter;
        }

        $importer = EnvImporters::openAgenda();
        if (!$importer instanceof OpenAgendaImporter) {
            $io->warning('OpenAgenda refresh skipped: OPENAGENDA_DATASET is not set.');
        }

        return $importer;
    }

    /**
     * The open zones, read from the registry: a zone with a geometry to clip against. The
     * same source of truth `provision` writes to (`osm.zones`), so opening and refreshing
     * agree on what is open.
     *
     * @return list<string>|null null when the registry could not be read, which must not
     *                           pass for "no zone is open"
     */
    private function openZones(SymfonyStyle $io): ?array
    {
        if (!is_dir($this->workDir) && !mkdir($this->workDir, 0o755, true) && !is_dir($this->workDir)) {
            $this->log->fail($io, \sprintf('Cannot create work directory "%s"', $this->workDir));

            return null;
        }

        $path = $this->workDir.'/open-zones.tsv';
        $process = $this->processes->process([
            'psql', '-v', 'ON_ERROR_STOP=1', '-c',
            \sprintf("\\copy (SELECT slug FROM osm.zones WHERE geom IS NOT NULL ORDER BY slug) TO '%s'", $path),
        ]);

        try {
            $process->run();
        } catch (ProcessExceptionInterface $processException) {
            $this->log->fail($io, \sprintf('Could not read the open zones: %s', $processException->getMessage()));

            return null;
        }

        if (!$process->isSuccessful() || !is_file($path)) {
            $this->log->fail($io, \sprintf('Could not read the open zones: %s', $process->getErrorOutput()));

            return null;
        }

        $contents = file_get_contents($path);
        if (false === $contents) {
            $this->log->fail($io, \sprintf('Could not read the open zones from "%s"', $path));

            return null;
        }

        $zones = [];
        foreach (explode("\n", $contents) as $line) {
            $slug = trim($line);
            if ('' !== $slug) {
                $zones[] = $slug;
            }
        }

        return $zones;
    }
}
