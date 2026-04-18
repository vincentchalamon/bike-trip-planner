<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Market;
use App\Repository\MarketRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:markets:import',
    description: 'Import weekly markets from data.gouv.fr (Licence Ouverte 2.0)',
)]
final class ImportMarketsCommand extends Command
{
    /** Maps French weekday names to ISO 8601 day numbers (1=Monday … 7=Sunday). */
    private const array DAY_OF_WEEK_MAP = [
        'lundi' => 1,
        'mardi' => 2,
        'mercredi' => 3,
        'jeudi' => 4,
        'vendredi' => 5,
        'samedi' => 6,
        'dimanche' => 7,
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
        'saturday' => 6,
        'sunday' => 7,
    ];

    public function __construct(
        private readonly MarketRepositoryInterface $marketRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'http_client')]
        private readonly HttpClientInterface $httpClient,
        #[Autowire(env: 'default:https://www.data.gouv.fr/fr/datasets/r/8067f5e0-15a7-48c3-9eb9-c9df2de96a1c:MARKETS_DATASET_URL')]
        private readonly string $datasetUrl,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show stats without writing to the database')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit the number of rows processed (for debug / CI)', 0);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $isDryRun = (bool) $input->getOption('dry-run');
        $limit = (int) $input->getOption('limit');

        $url = $this->datasetUrl;

        $io->title('Import weekly markets from data.gouv.fr');

        if ($isDryRun) {
            $io->note('Dry-run mode: no data will be written.');
        }

        $io->text(\sprintf('Downloading dataset from: %s', $url));

        $tmpFile = $this->downloadToTempFile($url);

        if (null === $tmpFile) {
            $io->error('Failed to download the dataset.');

            return Command::FAILURE;
        }

        try {
            [$inserted, $updated, $skipped] = $this->processFile($tmpFile, $isDryRun, $limit);
        } finally {
            @unlink($tmpFile);
        }

        if (!$isDryRun) {
            $this->entityManager->flush();
        }

        $io->success(\sprintf(
            '%d inserted, %d updated, %d skipped (missing geo)',
            $inserted,
            $updated,
            $skipped,
        ));

        return Command::SUCCESS;
    }

    private function downloadToTempFile(string $url): ?string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'markets_import_');

        if (false === $tmpFile) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 60]);
            $fileHandle = fopen($tmpFile, 'w');

            if (false === $fileHandle) {
                return null;
            }

            foreach ($this->httpClient->stream($response) as $chunk) {
                fwrite($fileHandle, $chunk->getContent());
            }

            fclose($fileHandle);
        } catch (\Throwable $throwable) {
            $this->logger->error('Failed to download markets dataset.', ['url' => $url, 'error' => $throwable->getMessage()]);
            @unlink($tmpFile);

            return null;
        }

        return $tmpFile;
    }

    /**
     * @return array{int, int, int}
     */
    private function processFile(string $filePath, bool $isDryRun, int $limit): array
    {
        $handle = fopen($filePath, 'r');

        if (false === $handle) {
            return [0, 0, 0];
        }

        $headers = fgetcsv($handle, 0, ';', escape: '\\');

        if (false === $headers || [] === $headers) {
            fclose($handle);

            return [0, 0, 0];
        }

        $headers = array_map(trim(...), $headers);
        $headerIndex = array_flip($headers);

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $processed = 0;
        $batchSize = 200;

        while (false !== ($row = fgetcsv($handle, 0, ';', escape: '\\'))) {
            if (0 < $limit && $processed >= $limit) {
                break;
            }

            /** @var array<string, string> $data */
            $data = [];
            foreach ($headers as $i => $header) {
                $data[$header] = isset($row[$i]) ? trim($row[$i]) : '';
            }

            [$lat, $lon] = $this->extractLatLon($data, $headerIndex);

            if (null === $lat || null === $lon) {
                ++$skipped;
                ++$processed;
                continue;
            }

            $externalId = $this->extractString($data, $headerIndex, ['id', 'ID', 'identifiant']);

            if ('' === $externalId) {
                $externalId = \sprintf('%F:%F', $lat, $lon);
            }

            $name = $this->extractString($data, $headerIndex, ['Nom du marché', 'nom_marche', 'nom', 'name', 'libelle']);

            if ('' === $name) {
                $name = 'Marché';
            }

            $dayOfWeek = $this->extractDayOfWeek($data, $headerIndex);

            if (null === $dayOfWeek) {
                ++$skipped;
                ++$processed;
                continue;
            }

            $commune = $this->extractString($data, $headerIndex, ['Commune', 'commune', 'ville', 'city']);
            $department = $this->extractString($data, $headerIndex, ['Département', 'departement', 'department', 'dep', 'code_departement']);
            $startTime = $this->extractTime($data, $headerIndex, ['Heure début', 'heure_debut', 'start_time', 'ouverture']);
            $endTime = $this->extractTime($data, $headerIndex, ['Heure fin', 'heure_fin', 'end_time', 'fermeture']);

            if (!$isDryRun) {
                $existing = $this->marketRepository->findByExternalId($externalId);

                if ($existing instanceof Market) {
                    $existing->setName($name);
                    $existing->setLat($lat);
                    $existing->setLon($lon);
                    $existing->setDayOfWeek($dayOfWeek);
                    $existing->setStartTime($startTime);
                    $existing->setEndTime($endTime);
                    $existing->setCommune($commune);
                    $existing->setDepartment($department);
                    $existing->setImportedAt(new \DateTimeImmutable());
                    ++$updated;
                } else {
                    $market = new Market($externalId, $name);
                    $market->setLat($lat);
                    $market->setLon($lon);
                    $market->setDayOfWeek($dayOfWeek);
                    $market->setStartTime($startTime);
                    $market->setEndTime($endTime);
                    $market->setCommune($commune);
                    $market->setDepartment($department);
                    $this->marketRepository->save($market);
                    ++$inserted;
                }

                if (0 === ($processed + 1) % $batchSize) {
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                }
            } else {
                $existing = $this->marketRepository->findByExternalId($externalId);
                if ($existing instanceof Market) {
                    ++$updated;
                } else {
                    ++$inserted;
                }
            }

            ++$processed;
        }

        fclose($handle);

        $this->logger->info('Markets import processed.', [
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
        ]);

        return [$inserted, $updated, $skipped];
    }

    /**
     * Extract lat/lon pair from row data.
     *
     * Handles three field layouts:
     *  - Separate "latitude" / "longitude" columns
     *  - A combined "Geo Point" column with "lat,lon" or "lat lon" format
     *
     * @param array<string, string> $data
     * @param array<string, int>    $headerIndex
     *
     * @return array{?float, ?float}
     */
    private function extractLatLon(array $data, array $headerIndex): array
    {
        // Try separate columns first
        $latValue = $this->extractScalarFloat($data, $headerIndex, ['latitude', 'lat']);
        $lonValue = $this->extractScalarFloat($data, $headerIndex, ['longitude', 'lon']);

        if (null !== $latValue && null !== $lonValue) {
            return [$latValue, $lonValue];
        }

        // Try combined geo_point "lat,lon" or "lat lon" column
        foreach (['Geo Point', 'geo_point', 'coordonnees', 'geolocalisation'] as $key) {
            if (!isset($headerIndex[$key])) {
                continue;
            }

            $value = trim($data[$key] ?? '');

            if ('' === $value) {
                continue;
            }

            // "lat,lon" format
            if (preg_match('/^(-?\d+\.?\d*)[,\s]+(-?\d+\.?\d*)$/', $value, $matches)) {
                $latParsed = filter_var($matches[1], \FILTER_VALIDATE_FLOAT);
                $lonParsed = filter_var($matches[2], \FILTER_VALIDATE_FLOAT);
                if (false !== $latParsed && false !== $lonParsed) {
                    return [$latParsed, $lonParsed];
                }
            }
        }

        return [null, null];
    }

    /**
     * @param array<string, string> $data
     * @param array<string, int>    $headerIndex
     * @param list<string>          $candidates
     */
    private function extractScalarFloat(array $data, array $headerIndex, array $candidates): ?float
    {
        foreach ($candidates as $key) {
            if (!isset($headerIndex[$key])) {
                continue;
            }

            $value = trim($data[$key] ?? '');

            if ('' === $value) {
                continue;
            }

            $floatVal = filter_var(str_replace(',', '.', $value), \FILTER_VALIDATE_FLOAT);
            if (false !== $floatVal) {
                return $floatVal;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $data
     * @param array<string, int>    $headerIndex
     * @param list<string>          $candidates
     */
    private function extractString(array $data, array $headerIndex, array $candidates): string
    {
        foreach ($candidates as $key) {
            if (!isset($headerIndex[$key])) {
                continue;
            }

            $value = $data[$key] ?? '';

            if ('' !== $value) {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, string> $data
     * @param array<string, int>    $headerIndex
     */
    private function extractDayOfWeek(array $data, array $headerIndex): ?int
    {
        $dayCandidates = ['Jour', 'jour', 'day_of_week', 'jour_semaine', 'jour_marche', 'jours'];

        $rawDay = $this->extractString($data, $headerIndex, $dayCandidates);

        if ('' === $rawDay) {
            return null;
        }

        $normalised = mb_strtolower(trim($rawDay));

        return self::DAY_OF_WEEK_MAP[$normalised] ?? null;
    }

    /**
     * @param array<string, string> $data
     * @param array<string, int>    $headerIndex
     * @param list<string>          $candidates
     */
    private function extractTime(array $data, array $headerIndex, array $candidates): ?string
    {
        $raw = $this->extractString($data, $headerIndex, $candidates);

        if ('' === $raw) {
            return null;
        }

        if (preg_match('/^(\d{1,2})[hH:](\d{2})/', $raw, $matches)) {
            return \sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
        }

        if (preg_match('/^(\d{1,2})h$/', $raw, $matches)) {
            return \sprintf('%02d:00', (int) $matches[1]);
        }

        return null;
    }
}
