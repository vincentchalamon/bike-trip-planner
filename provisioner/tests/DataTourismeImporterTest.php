<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Provisioner\DataTourismeImporter;
use Provisioner\Exception\ImportFailedException;
use Provisioner\WikidataEnricher;
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
            // Eatery → mapped to the food head (food_pois COPY file).
            $this->place('objects/0/00/food.json', [
                '@id' => 'https://data.datatourisme.fr/10/food',
                '@type' => ['FoodEstablishment', 'Restaurant'],
                'rdfs:label' => ['fr' => ['Resto test']],
                'isLocatedAt' => [['schema:geo' => ['schema:latitude' => '44.0', 'schema:longitude' => '4.0']]],
            ]),
            // Food shop (Store + LocalProductsShop) → also the food head, exercising
            // the Store branch end-to-end through the COPY pipeline.
            $this->place('objects/0/00/farm.json', [
                '@id' => 'https://data.datatourisme.fr/10/farm',
                '@type' => ['LocalProductsShop', 'Store'],
                'rdfs:label' => ['fr' => ['Épicerie locale']],
                'isLocatedAt' => [['schema:geo' => ['schema:latitude' => '43.5', 'schema:longitude' => '3.5']]],
            ]),
            // Non-food store → still skipped, not written to any COPY file.
            $this->place('objects/0/00/shop.json', [
                '@id' => 'https://data.datatourisme.fr/10/shop',
                '@type' => ['Store', 'BoutiqueOrLocalShop'],
                'rdfs:label' => ['fr' => ['Boutique test']],
                'isLocatedAt' => [['schema:geo' => ['schema:latitude' => '43.0', 'schema:longitude' => '3.0']]],
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

        // 1 staging DDL + 4 \copy + 4 GIST index + 1 events-date index + 1 metadata + 1 swap.
        self::assertCount(12, $this->captured);

        $ddl = implode(' ', $this->captured[0]);
        self::assertStringContainsString('CREATE SCHEMA tourism_staging', $ddl);
        self::assertStringContainsString('CREATE TABLE tourism_staging.cultural_pois', $ddl);
        self::assertStringContainsString('CREATE TABLE tourism_staging.food_pois', $ddl);
        self::assertStringContainsString('CREATE TABLE tourism_staging.events', $ddl);

        $joinedAll = array_map(static fn (array $c): string => implode(' ', $c), $this->captured);
        self::assertTrue(
            (bool) array_filter($joinedAll, static fn (string $c): bool => str_contains($c, 'CREATE TABLE tourism_staging.metadata AS') && str_contains($c, "'events', (SELECT count(*) FROM tourism_staging.events)")),
            'a metadata command records per-table counts before the swap',
        );

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
        $food = (string) file_get_contents($this->workDir.'/tourism-food_pois.copy');
        $events = (string) file_get_contents($this->workDir.'/tourism-events.copy');
        $accommodations = (string) file_get_contents($this->workDir.'/tourism-accommodations.copy');

        self::assertSame(1, substr_count($cultural, "\n"), 'one cultural row');
        self::assertSame(2, substr_count($food, "\n"), 'two food rows (eatery + food store)');
        self::assertSame(1, substr_count($events, "\n"), 'one event row');
        self::assertSame('', $accommodations, 'no accommodation in the fixture');

        // The eatery (restaurant) and the food store (LocalProductsShop → farm) both
        // land in food_pois; the non-food store is skipped.
        self::assertStringContainsString('restaurant', $food);
        self::assertStringContainsString('farm', $food);
        self::assertStringContainsString('https://data.datatourisme.fr/10/farm', $food);
        self::assertStringContainsString('SRID=4326;POINT(', $cultural);
        self::assertStringContainsString('https://data.datatourisme.fr/10/cultural', $cultural);
        self::assertStringContainsString('https://data.datatourisme.fr/10/food', $food);
        self::assertStringContainsString('2026-07-01', $events);
        self::assertStringNotContainsString('food', $cultural.$events);
        self::assertStringNotContainsString('shop', $cultural.$food.$events);
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
    public function enrichesWikidataBearingRowsBetweenLoadAndSwap(): void
    {
        // A cultural POI carrying a Wikidata Q-ID (owl:sameAs) triggers the
        // post-load enrichment pass before the atomic swap.
        $zipPath = $this->workDir.'/enriched.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('objects/0/00/cultural.json', (string) json_encode([
            '@id' => 'https://data.datatourisme.fr/10/cultural',
            '@type' => ['CulturalSite', 'Museum', 'PointOfInterest'],
            'rdfs:label' => ['fr' => ['Musee test']],
            'owl:sameAs' => ['https://www.wikidata.org/entity/Q243'],
            'isLocatedAt' => [['schema:geo' => ['schema:latitude' => '48.5', 'schema:longitude' => '2.3']]],
        ]));
        $zip->close();

        $bytes = (string) file_get_contents($zipPath);
        unlink($zipPath);

        $sparql = new MockHttpClient(new MockResponse((string) json_encode([
            'results' => ['bindings' => [[
                'item' => ['value' => 'http://www.wikidata.org/entity/Q243'],
                'website' => ['value' => 'https://museum.test'],
                'article' => ['value' => 'https://fr.wikipedia.org/wiki/Musee'],
            ]]],
        ])));

        $importer = new DataTourismeImporter(
            fluxUrl: 'https://example.test/flux',
            httpClient: new MockHttpClient(new MockResponse($bytes)),
            processFactory: $this->capturingFactory(),
            enricher: new WikidataEnricher($sparql),
        );
        $importer->run($this->workDir);

        $joined = array_map(static fn (array $c): string => implode(' ', $c), $this->captured);

        self::assertTrue(
            (bool) array_filter($joined, static fn (string $c): bool => str_contains($c, 'CREATE TABLE tourism_staging.wikidata_enrich')),
            'an enrichment staging table is created',
        );
        self::assertTrue(
            (bool) array_filter($joined, static fn (string $c): bool => str_contains($c, 'UPDATE tourism_staging.cultural_pois t SET') && str_contains($c, 'website = e.website') && str_contains($c, 'COALESCE(t.description, e.description)')),
            'cultural_pois is updated from the enrichment table, keeping the source description',
        );
        self::assertTrue(
            (bool) array_filter($joined, static fn (string $c): bool => str_contains($c, 'UPDATE tourism_staging.food_pois t SET')),
            'food_pois is updated from the enrichment table',
        );
        self::assertTrue(
            (bool) array_filter($joined, static fn (string $c): bool => str_contains($c, 'DROP TABLE tourism_staging.wikidata_enrich')),
            'the enrichment staging table is dropped before the swap',
        );

        $enrich = (string) file_get_contents($this->workDir.'/tourism-wikidata.copy');
        self::assertStringContainsString('Q243', $enrich);
        self::assertStringContainsString('https://museum.test', $enrich);
        self::assertStringContainsString('https://fr.wikipedia.org/wiki/Musee', $enrich);

        // The DROP of the enrichment table must precede the schema swap.
        $dropIndex = $this->commandIndex('DROP TABLE tourism_staging.wikidata_enrich');
        $swapIndex = $this->commandIndex('ALTER SCHEMA tourism_staging RENAME TO tourism');
        self::assertGreaterThan(-1, $dropIndex);
        self::assertGreaterThan($dropIndex, $swapIndex);
    }

    private function commandIndex(string $needle): int
    {
        foreach ($this->captured as $index => $command) {
            if (str_contains(implode(' ', $command), $needle)) {
                return $index;
            }
        }

        return -1;
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
