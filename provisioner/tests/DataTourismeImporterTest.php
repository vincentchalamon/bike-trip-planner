<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Provisioner\DataTourismeImporter;
use Provisioner\Exception\ImportFailedException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\Process;

final class DataTourismeImporterTest extends TestCase
{
    private string $workDir;

    /**
     * @var list<list<string>>
     */
    private array $captured = [];

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir().'/dt-importer-'.uniqid('', true);
        mkdir($this->workDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir.'/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->workDir)) {
            rmdir($this->workDir);
        }
    }

    /**
     * @param array<string, mixed> $object
     *
     * @return array{0: string, 1: string}
     */
    private function place(string $relativePath, array $object): array
    {
        return [$relativePath, (string) json_encode($object)];
    }

    /**
     * Builds a tiny flux ZIP (index + objects/) and returns its raw bytes.
     */
    private function fluxZipBytes(): string
    {
        $zipPath = $this->workDir.'/fixture.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('index.json', '[]');
        $zip->addFromString('context.jsonld', '{}');

        $entries = [
            $this->place('objects/0/00/cultural.json', [
                '@id' => 'https://data.datatourisme.fr/10/cultural',
                '@type' => ['CulturalSite', 'Museum', 'PointOfInterest'],
                'rdfs:label' => ['fr' => ['Musée test']],
                'isLocatedAt' => [['schema:geo' => ['schema:latitude' => '48.5', 'schema:longitude' => '2.3']]],
            ]),
            $this->place('objects/0/00/event.json', [
                '@id' => 'https://data.datatourisme.fr/10/event',
                '@type' => ['EntertainmentAndEvent', 'Festival'],
                'rdfs:label' => ['fr' => ['Festival test']],
                'schema:startDate' => ['2026-07-01'],
                'schema:endDate' => ['2026-07-03'],
                'isLocatedAt' => [['schema:geo' => ['schema:latitude' => '45.0', 'schema:longitude' => '5.0']]],
            ]),
            // Out of scope (food) → must be skipped, not written to any COPY file.
            $this->place('objects/0/00/food.json', [
                '@id' => 'https://data.datatourisme.fr/10/food',
                '@type' => ['FoodEstablishment', 'Restaurant'],
                'rdfs:label' => ['fr' => ['Resto test']],
                'isLocatedAt' => [['schema:geo' => ['schema:latitude' => '44.0', 'schema:longitude' => '4.0']]],
            ]),
        ];
        foreach ($entries as [$name, $contents]) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();
        $bytes = (string) file_get_contents($zipPath);
        unlink($zipPath);

        return $bytes;
    }

    private function capturingFactory(): \Closure
    {
        return function (array $command): Process {
            /** @var list<string> $cmd */
            $cmd = $command;
            $this->captured[] = $cmd;

            return new Process(['true']);
        };
    }

    #[Test]
    public function runStreamsTheFluxIntoStagingCopyFilesThenSwaps(): void
    {
        $httpClient = new MockHttpClient(new MockResponse($this->fluxZipBytes()));

        $importer = new DataTourismeImporter(
            fluxUrl: 'https://diffuseur.datatourisme.fr/webservice/flux/key',
            httpClient: $httpClient,
            processFactory: $this->capturingFactory(),
        );

        $importer->run($this->workDir);

        // 1 staging DDL + 3 \copy + 3 GIST index + 1 events-date index + 1 swap.
        self::assertCount(9, $this->captured);

        $ddl = implode(' ', $this->captured[0]);
        self::assertStringContainsString('CREATE SCHEMA tourism_staging', $ddl);
        self::assertStringContainsString('CREATE TABLE tourism_staging.cultural_pois', $ddl);
        self::assertStringContainsString('CREATE TABLE tourism_staging.events', $ddl);

        $joined = array_map(static fn (array $c): string => implode(' ', $c), $this->captured);
        self::assertTrue(
            (bool) array_filter($joined, static fn (string $c): bool => str_contains($c, '\copy tourism_staging.cultural_pois')),
            'a COPY command targets tourism_staging.cultural_pois',
        );

        $last = end($this->captured);
        self::assertNotFalse($last);
        $swap = implode(' ', $last);
        self::assertStringContainsString('DROP SCHEMA IF EXISTS tourism CASCADE', $swap);
        self::assertStringContainsString('ALTER SCHEMA tourism_staging RENAME TO tourism', $swap);
    }

    #[Test]
    public function copyFilesContainOnlyTheInScopeMappedRows(): void
    {
        $httpClient = new MockHttpClient(new MockResponse($this->fluxZipBytes()));

        $importer = new DataTourismeImporter(
            fluxUrl: 'https://example.test/flux',
            httpClient: $httpClient,
            processFactory: $this->capturingFactory(),
        );

        $importer->run($this->workDir);

        $cultural = (string) file_get_contents($this->workDir.'/tourism-cultural_pois.copy');
        $events = (string) file_get_contents($this->workDir.'/tourism-events.copy');
        $accommodations = (string) file_get_contents($this->workDir.'/tourism-accommodations.copy');

        self::assertSame(1, substr_count($cultural, "\n"), 'one cultural row');
        self::assertSame(1, substr_count($events, "\n"), 'one event row');
        self::assertSame('', $accommodations, 'no accommodation in the fixture');

        // The geometry column carries EWKT and the food POI was skipped.
        self::assertStringContainsString('SRID=4326;POINT(', $cultural);
        self::assertStringContainsString('https://data.datatourisme.fr/10/cultural', $cultural);
        self::assertStringContainsString('2026-07-01', $events);
        self::assertStringNotContainsString('food', $cultural.$events);
    }

    #[Test]
    public function escapesSpecialCharactersInCopyFields(): void
    {
        // The real flux has labels with tabs/newlines; unescaped they would split
        // or break COPY rows and abort the whole load under ON_ERROR_STOP=1.
        $zipPath = $this->workDir.'/special.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('objects/0/00/special.json', (string) json_encode([
            '@id' => 'https://data.datatourisme.fr/10/special',
            '@type' => ['CulturalSite', 'Museum', 'PointOfInterest'],
            'rdfs:label' => ['fr' => ["Name\twith\ttabs\nand newline\\backslash"]],
            'isLocatedAt' => [['schema:geo' => ['schema:latitude' => '48.0', 'schema:longitude' => '2.0']]],
        ]));
        $zip->close();

        $bytes = (string) file_get_contents($zipPath);
        unlink($zipPath);

        $importer = new DataTourismeImporter(
            fluxUrl: 'https://example.test/flux',
            httpClient: new MockHttpClient(new MockResponse($bytes)),
            processFactory: $this->capturingFactory(),
        );
        $importer->run($this->workDir);

        $cultural = (string) file_get_contents($this->workDir.'/tourism-cultural_pois.copy');
        self::assertStringNotContainsString("\t\t", $cultural, 'a literal tab in the name would split into extra columns');
        self::assertStringContainsString('\t', $cultural, 'tab escaped as backslash-t');
        self::assertStringContainsString('\n', $cultural, 'newline escaped as backslash-n');
        self::assertStringContainsString('\\\\', $cultural, 'backslash escaped as double backslash');
    }

    #[Test]
    public function downloadFailureRaisesImportFailedException(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('not found', ['http_code' => 404]));

        $importer = new DataTourismeImporter(
            fluxUrl: 'https://example.test/flux',
            httpClient: $httpClient,
            processFactory: $this->capturingFactory(),
        );

        $this->expectException(ImportFailedException::class);
        $importer->run($this->workDir);
    }
}
