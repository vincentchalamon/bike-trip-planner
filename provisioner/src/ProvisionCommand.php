<?php

declare(strict_types=1);

namespace Provisioner;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'provision',
    description: 'Download OSM regions and merge them into a single PBF file',
)]
final class ProvisionCommand extends Command
{
    private const string REGIONS_DIR = '/data/regions';

    private const string MERGED_PBF = '/data/default.osm.pbf';

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be downloaded without executing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('OSM Region Provisioner');

        $dryRun = (bool) $input->getOption('dry-run');
        $allRegions = GeofabrikRegionRegistry::all();
        $regionNames = array_keys($allRegions);

        // Detect already-downloaded regions
        $existingPbfs = [];
        if (is_dir(self::REGIONS_DIR)) {
            foreach (glob(self::REGIONS_DIR.'/*.osm.pbf') ?: [] as $file) {
                $existingPbfs[] = basename($file, '-latest.osm.pbf');
            }
        }

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

            // Extract region name (strip size suffix if present)
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

        // Summary
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

        // Ensure regions directory exists
        if (!is_dir(self::REGIONS_DIR)) {
            mkdir(self::REGIONS_DIR, 0o755, true);
        }

        // Download PBFs
        $httpClient = HttpClient::create();
        $toDownload = [];
        foreach ($selected as $name) {
            $slug = $allRegions[$name]['slug'];
            $targetPath = \sprintf('%s/%s-latest.osm.pbf', self::REGIONS_DIR, $slug);

            if (file_exists($targetPath)) {
                $io->writeln(\sprintf('  [skip] %s (already downloaded)', $name));
                continue;
            }

            $toDownload[] = ['name' => $name, 'slug' => $slug, 'path' => $targetPath];
        }

        $total = \count($toDownload);
        foreach ($toDownload as $i => $region) {
            $io->write(\sprintf('  [%d/%d] Downloading %s... ', $i + 1, $total, $region['name']));
            $url = GeofabrikRegionRegistry::downloadUrl($region['slug']);

            $response = $httpClient->request('GET', $url);
            $fileHandle = fopen($region['path'], 'w');

            if (false === $fileHandle) {
                $io->error(\sprintf('Cannot write to %s', $region['path']));

                return Command::FAILURE;
            }

            foreach ($httpClient->stream($response) as $chunk) {
                fwrite($fileHandle, $chunk->getContent());
            }

            fclose($fileHandle);
            $io->writeln("\u{2713}");
        }

        // Merge PBFs with osmium
        $pbfFiles = glob(self::REGIONS_DIR.'/*.osm.pbf') ?: [];
        if ([] !== $pbfFiles) {
            $io->write('  Merging PBF files with osmium... ');

            $mergeCmd = array_merge(
                ['osmium', 'merge', '--overwrite', '-o', self::MERGED_PBF],
                $pbfFiles,
            );

            $process = new Process($mergeCmd);
            $process->setTimeout(600);
            $process->run();

            if (!$process->isSuccessful()) {
                $io->error('osmium merge failed: '.$process->getErrorOutput());

                return Command::FAILURE;
            }

            $io->writeln("\u{2713}");
        }

        $io->success('Done! The merged PBF is ready at '.self::MERGED_PBF);

        return Command::SUCCESS;
    }
}
