<?php

declare(strict_types=1);

namespace Provisioner;

use Provisioner\Exception\DownloadFailedException;
use Provisioner\Exception\ImportFailedException;
use Provisioner\Exception\MergeFailedException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'provision',
    description: 'Download OSM regions and merge them into a single PBF file',
)]
final class ProvisionCommand extends Command
{
    private const string DEFAULT_REGIONS_DIR = '/data/regions';

    private const string DEFAULT_MERGED_PBF = '/data/default.osm.pbf';

    private const string DEFAULT_SELECTION_FILE = '/data/regions.json';

    private const string DEFAULT_FILTERED_PBF = '/data/tier1-filtered.osm.pbf';

    private const string DEFAULT_DATATOURISME_DIR = '/data/datatourisme';

    private const string DEFAULT_LOCK_FILE = '/data/provision.lock';

    private const string DEFAULT_LOG_FILE = '/data/provisioner.log';

    /**
     * @var resource|null held for the whole command so the flock is released only
     *                    when the process ends (incl. a crash: the OS drops it)
     */
    private $lockHandle;

    private readonly RegionSelectionStore $selectionStore;

    private readonly OsmDataDownloader $downloader;

    private readonly PostgisImporter $postgisImporter;

    public function __construct(
        private readonly string $regionsDir = self::DEFAULT_REGIONS_DIR,
        private readonly string $mergedPbf = self::DEFAULT_MERGED_PBF,
        string $selectionFile = self::DEFAULT_SELECTION_FILE,
        ?RegionSelectionStore $selectionStore = null,
        ?OsmDataDownloader $downloader = null,
        private readonly bool $runMerge = true,
        private readonly string $filteredPbf = self::DEFAULT_FILTERED_PBF,
        ?PostgisImporter $postgisImporter = null,
        private readonly string $dataTourismeDir = self::DEFAULT_DATATOURISME_DIR,
        // Built lazily in runDataTourisme() from DATATOURISME_* env when not injected.
        private readonly ?DataTourismeImporter $dataTourismeImporter = null,
        private readonly string $lockFile = self::DEFAULT_LOCK_FILE,
        private readonly string $logFile = self::DEFAULT_LOG_FILE,
    ) {
        parent::__construct();

        $this->selectionStore = $selectionStore ?? new RegionSelectionStore($selectionFile);
        $this->downloader = $downloader ?? new OsmDataDownloader(regionsDir: $this->regionsDir);
        $this->postgisImporter = $postgisImporter ?? new PostgisImporter(
            flexStylePath: \dirname(__DIR__).'/osm2pgsql/tier1.lua',
        );
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be downloaded without executing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('OSM Region Provisioner');

        $dryRun = (bool) $input->getOption('dry-run');
        $interactive = $input->isInteractive();
        $hasSelection = $this->selectionStore->exists();

        if (!$hasSelection && !$interactive) {
            $io->error('First run requires interactive setup. Run `make provision` once manually.');

            return Command::FAILURE;
        }

        // Serialise concurrent runs (cron + manual overlap): two provisioners
        // writing the same staging schema would race destructively (ADR-041).
        if (!$this->acquireLock($io)) {
            return Command::FAILURE;
        }

        try {
            // Each reference source runs as its own step (own download, schema and
            // atomic swap) and is attempted independently: one source failing must
            // not abort the others, so a single bad refresh degrades only its own
            // dataset (ADR-041). Outcomes are aggregated into the final exit code.
            $outcomes = [];

            $outcomes['osm'] = $hasSelection
                ? $this->runUpdateFlow($io, $dryRun, $interactive)
                : $this->runInstallFlow($io, $dryRun);

            if (!$dryRun) {
                $outcomes['datatourisme'] = $this->runDataTourisme($io);
            }

            $this->summarize($io, $outcomes);

            return \in_array(Command::FAILURE, $outcomes, true) ? Command::FAILURE : Command::SUCCESS;
        } finally {
            $this->releaseLock();
        }
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

    private function runDataTourisme(SymfonyStyle $io): int
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
            $importer->run($this->dataTourismeDir);
        } catch (ImportFailedException $importFailedException) {
            $this->fail($io, $importFailedException->getMessage());

            return Command::FAILURE;
        }

        $io->success('DataTourisme import complete.');

        return Command::SUCCESS;
    }

    private function runInstallFlow(SymfonyStyle $io, bool $dryRun): int
    {
        $allRegions = GeofabrikRegionRegistry::all();
        $regionNames = array_keys($allRegions);
        $existingPbfs = $this->detectExistingPbfs();

        $selected = [];
        $isFirst = true;

        while (true) {
            $prompt = $isFirst
                ? 'Which region do you want to add?'
                : 'Which region do you want to add? (leave empty to finish)';
            $isFirst = false;

            $available = array_diff($regionNames, $selected);
            $question = new Question($prompt);
            $question->setAutocompleterValues(
                array_map(
                    static fn (string $name): string => \sprintf('%s (%s)', $name, $allRegions[$name]['size']),
                    array_values($available),
                ),
            );

            /** @var string|null $answer */
            $answer = $io->askQuestion($question);

            if (null === $answer || '' === trim($answer)) {
                if ([] === $selected) {
                    $io->warning('No region selected.');

                    return Command::SUCCESS;
                }

                break;
            }

            $regionName = (string) preg_replace('/\s*\(.*\)$/', '', trim($answer));

            if (!isset($allRegions[$regionName])) {
                $io->error(\sprintf('Unknown region: "%s"', $answer));
                continue;
            }

            if (\in_array($regionName, $selected, true)) {
                $io->warning(\sprintf('"%s" is already selected.', $regionName));
                continue;
            }

            $selected[] = $regionName;

            $alreadyDownloaded = \in_array($allRegions[$regionName]['slug'], $existingPbfs, true);
            $io->success(\sprintf(
                'Added: %s%s',
                $regionName,
                $alreadyDownloaded ? ' (already downloaded)' : '',
            ));
        }

        $io->section('Selected regions');
        foreach ($selected as $name) {
            $alreadyDownloaded = \in_array($allRegions[$name]['slug'], $existingPbfs, true);
            $io->writeln(\sprintf(
                '  %s %s (%s)%s',
                $alreadyDownloaded ? "\u{2713}" : "\u{2022}",
                $name,
                $allRegions[$name]['size'],
                $alreadyDownloaded ? ' [cached]' : '',
            ));
        }

        if ($dryRun) {
            $io->note('Dry run — no downloads or merges will be performed.');

            return Command::SUCCESS;
        }

        if (!$io->confirm('Do you want to proceed?', true)) {
            return Command::SUCCESS;
        }

        $slugs = array_map(static fn (string $name): string => $allRegions[$name]['slug'], $selected);

        $result = $this->downloadAndMerge($io, $slugs, force: false);
        if (Command::SUCCESS !== $result) {
            return $result;
        }

        $this->selectionStore->save(array_values($slugs));
        $io->success('Done! The merged PBF is ready at '.$this->mergedPbf);

        return Command::SUCCESS;
    }

    private function runUpdateFlow(SymfonyStyle $io, bool $dryRun, bool $interactive): int
    {
        $slugs = $this->selectionStore->load();
        $knownSlugs = array_column(GeofabrikRegionRegistry::all(), 'slug');
        $unknown = array_values(array_diff($slugs, $knownSlugs));

        if ([] !== $unknown) {
            $io->error(\sprintf(
                'Selection file contains unknown slugs: %s. Run `make provision` interactively to reconfigure.',
                implode(', ', $unknown),
            ));

            return Command::FAILURE;
        }

        if ([] === $slugs) {
            if (!$interactive) {
                $io->error('Selection file exists but is empty or invalid. Run `make provision` interactively to recreate it.');

                return Command::FAILURE;
            }

            $io->warning('Selection file exists but is empty or invalid. Falling back to install flow.');

            return $this->runInstallFlow($io, $dryRun);
        }

        if (!$interactive) {
            return $this->runSilentUpdate($io, $slugs, $dryRun);
        }

        $choice = $io->choice(
            'Selection already exists. What do you want to do?',
            ['update', 'reconfigure', 'cancel'],
            'update',
        );

        return match ($choice) {
            'update' => $this->runSilentUpdate($io, $slugs, $dryRun),
            'reconfigure' => $this->runInstallFlow($io, $dryRun),
            default => Command::SUCCESS,
        };
    }

    /**
     * @param list<string> $slugs
     */
    private function runSilentUpdate(SymfonyStyle $io, array $slugs, bool $dryRun): int
    {
        $io->section('Updating persisted regions');
        foreach ($slugs as $slug) {
            $io->writeln(\sprintf('  %s %s', "\u{2022}", $slug));
        }

        if ($dryRun) {
            $io->note('Dry run — no downloads or merges will be performed.');

            return Command::SUCCESS;
        }

        $result = $this->downloadAndMerge($io, $slugs, force: true);
        if (Command::SUCCESS !== $result) {
            return $result;
        }

        $io->success('Update complete. The merged PBF is ready at '.$this->mergedPbf);

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $slugs
     */
    private function downloadAndMerge(SymfonyStyle $io, array $slugs, bool $force): int
    {
        $toDownload = [];
        foreach ($slugs as $slug) {
            $targetPath = $this->downloader->targetPath($slug);

            if (!$force && file_exists($targetPath)) {
                $io->writeln(\sprintf('  [skip] %s (already downloaded)', $slug));
                continue;
            }

            $toDownload[] = $slug;
        }

        $total = \count($toDownload);
        foreach ($toDownload as $i => $slug) {
            $io->write(\sprintf('  [%d/%d] Downloading %s... ', $i + 1, $total, $slug));

            try {
                $this->downloader->download($slug);
            } catch (DownloadFailedException $e) {
                $io->newLine();
                $this->fail($io, $e->getMessage());

                return Command::FAILURE;
            }

            $io->writeln("\u{2713}");
        }

        if (!$this->runMerge) {
            return Command::SUCCESS;
        }

        $pbfFiles = glob($this->regionsDir.'/*.osm.pbf') ?: [];
        if ([] !== $pbfFiles) {
            $io->write('  Merging PBF files with osmium... ');

            try {
                $this->downloader->merge($pbfFiles, $this->mergedPbf);
            } catch (MergeFailedException $e) {
                $io->newLine();
                $this->fail($io, $e->getMessage());

                return Command::FAILURE;
            }

            $io->writeln("\u{2713}");
        }

        $io->write('  Importing Tier-1 features into PostGIS... ');

        try {
            $this->postgisImporter->run($this->mergedPbf, $this->filteredPbf);
        } catch (ImportFailedException $importFailedException) {
            $io->newLine();
            $this->fail($io, $importFailedException->getMessage());

            return Command::FAILURE;
        }

        $io->writeln("\u{2713}");

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function detectExistingPbfs(): array
    {
        $existing = [];
        if (is_dir($this->regionsDir)) {
            foreach (glob($this->regionsDir.'/*.osm.pbf') ?: [] as $file) {
                $existing[] = basename($file, '-latest.osm.pbf');
            }
        }

        return $existing;
    }
}
