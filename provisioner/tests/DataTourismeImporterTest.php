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

    /**
     * @param list<string> $missingQids written to the `\copy (… missing …) TO 'file'`
     *                                  destination so the enrichment fetch path runs in tests
     */
    private function capturingFactory(array $missingQids = []): \Closure
    {
        return function (array $command) use ($missingQids): Process {
            /** @var list<string> $cmd */
            $cmd = $command;
            $this->captured[] = $cmd;

            // Emulate psql exporting the missing Q-IDs (the enrichment pass reads
            // this file back); the no-op `true` process never writes it itself.
            if ([] !== $missingQids && 1 === preg_match("/TO '([^']+)'/", implode(' ', $cmd), $matches)) {
                file_put_contents($matches[1], implode("\n", $missingQids)."\n");
            }

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

        // 1 staging DDL + 4 \copy + 4 GIST index + 1 events-date index + 1 metadata
        // + 7 enrichment-pass psql calls (prepare, collect, export, one UPDATE per
        // Wikidata table, drop; no Q-IDs to fetch in this fixture) + 1 swap.
        self::assertCount(19, $this->captured);

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
    public function importsRentalsAndPublishesTheUnmappedAccommodationCount(): void
    {
        // A meublé lands in the `rental` category; an Accommodation whose subtype
        // maps to nothing is dropped rather than folded into a bucket the app
        // cannot query back, and counted so it stays visible (issue #865).
        $zipPath = $this->workDir.'/accommodations.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('objects/0/00/rental.json', (string) json_encode([
            '@id' => 'https://data.datatourisme.fr/10/rental',
            '@type' => ['schema:Accommodation', 'Accommodation', 'RentalAccommodation', 'SelfCateringAccommodation'],
            'rdfs:label' => ['fr' => ['Gite du Lac']],
            'isLocatedAt' => [['schema:geo' => ['schema:latitude' => '48.5', 'schema:longitude' => '2.3']]],
        ]));
        $zip->addFromString('objects/0/00/unmapped.json', (string) json_encode([
            '@id' => 'https://data.datatourisme.fr/10/unmapped',
            '@type' => ['schema:Accommodation', 'Accommodation', 'PlaceOfInterest'],
            'rdfs:label' => ['fr' => ['Hebergement sans sous-type']],
            'isLocatedAt' => [['schema:geo' => ['schema:latitude' => '48.6', 'schema:longitude' => '2.4']]],
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

        $accommodations = (string) file_get_contents($this->workDir.'/tourism-accommodations.copy');
        self::assertSame(1, substr_count($accommodations, "\n"), 'only the rental row is written');
        self::assertStringContainsString('rental', $accommodations);
        self::assertStringNotContainsString('unmapped', $accommodations);
        self::assertSame(1, $importer->unmappedAccommodationCount());
    }

    #[Test]
    public function enrichesWikidataBearingRowsFromTheCacheBetweenLoadAndSwap(): void
    {
        // A cultural POI carrying a Wikidata Q-ID (owl:sameAs) triggers the
        // post-load, cache-backed enrichment pass before the atomic swap.
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
            // The cache is empty, so emulate psql exporting Q243 as the missing Q-ID.
            processFactory: $this->capturingFactory(['Q243']),
            enricher: new WikidataEnricher($sparql),
        );
        $importer->run($this->workDir);

        $joined = array_map(static fn (array $c): string => implode(' ', $c), $this->captured);
        $has = static fn (string ...$needles): bool => (bool) array_filter(
            $joined,
            static fn (string $c): bool => array_all($needles, static fn (string $n): bool => str_contains($c, $n)),
        );

        self::assertTrue($has('CREATE TABLE IF NOT EXISTS provisioner.wikidata_cache'), 'the persistent cache is ensured');
        self::assertTrue($has('CREATE TABLE provisioner.wikidata_candidates'), 'a candidates scratch table is created');
        self::assertTrue(
            $has('INSERT INTO provisioner.wikidata_candidates', 'SELECT DISTINCT wikidata FROM tourism_staging.cultural_pois', 'SELECT DISTINCT wikidata FROM tourism_staging.food_pois'),
            'candidate Q-IDs are collected straight from the staging tables',
        );
        self::assertTrue($has('TO ', 'SELECT cand.qid', 'NOT EXISTS', 'make_interval'), 'missing/stale Q-IDs are selected against the TTL');
        self::assertTrue($has('\copy provisioner.wikidata_fetch_tourism_staging (qid, payload) FROM'), 'fetched enrichments are staged');
        self::assertTrue($has('INSERT INTO provisioner.wikidata_cache', 'ON CONFLICT (qid) DO UPDATE'), 'fetched enrichments are upserted into the cache');
        self::assertTrue(
            $has('UPDATE tourism_staging.cultural_pois t SET', "COALESCE(t.website, c.payload->>'website')", "COALESCE(t.description, c.payload->>'description')", 'FROM provisioner.wikidata_cache c'),
            'cultural_pois is enriched from the cache, keeping source-set fields',
        );
        self::assertTrue($has('UPDATE tourism_staging.food_pois t SET', 'FROM provisioner.wikidata_cache c'), 'food_pois is enriched from the cache');
        // accommodations joined the enriched tables with its `wikidata` column (#872).
        self::assertTrue(
            $has('INSERT INTO provisioner.wikidata_candidates', 'SELECT DISTINCT wikidata FROM tourism_staging.accommodations'),
            'accommodation Q-IDs are collected too',
        );
        self::assertTrue(
            $has('UPDATE tourism_staging.accommodations t SET', "image_url = c.payload->>'imageUrl'", "wikipedia_url = c.payload->>'wikipediaUrl'", 'FROM provisioner.wikidata_cache c'),
            'accommodations receives the Wikidata description, image and Wikipedia URL',
        );
        self::assertTrue($has('DROP TABLE IF EXISTS provisioner.wikidata_candidates_tourism_staging, provisioner.wikidata_fetch_tourism_staging'), 'scratch tables are dropped');

        $fetch = (string) file_get_contents($this->workDir.'/wikidata-fetch.copy');
        self::assertStringContainsString('Q243', $fetch);
        self::assertStringContainsString('https://museum.test', $fetch);
        self::assertStringContainsString('https://fr.wikipedia.org/wiki/Musee', $fetch);

        // The scratch tables are dropped before the schema swap.
        $dropIndex = $this->commandIndex('DROP TABLE IF EXISTS provisioner.wikidata_candidates');
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

    /**
     * The `tags` jsonb used to carry the type list alone and `website` was missing
     * from the COPY column list although the DDL creates it, so both the column and
     * everything else the flux publishes were dropped at import time (#871).
     */
    #[Test]
    public function loadsTheWebsiteColumnAndTheFullTagsPayload(): void
    {
        $zipPath = $this->workDir.'/rich.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('objects/0/00/cultural.json', (string) json_encode([
            '@id' => 'https://data.datatourisme.fr/10/cultural',
            '@type' => ['CulturalSite', 'Museum'],
            'rdfs:label' => ['fr' => ['Musee test']],
            'hasContact' => [[
                '@type' => ['Agent'],
                'foaf:homepage' => ['https://musee.test'],
                'schema:telephone' => ['+33 3 88 00 00 00'],
            ]],
            'isLocatedAt' => [[
                '@type' => ['PlaceOfInterest'],
                'schema:geo' => ['schema:latitude' => '48.5', 'schema:longitude' => '2.3'],
                'schema:openingHoursSpecification' => [[
                    '@type' => ['schema:OpeningHoursSpecification'],
                    'schema:validFrom' => '2026-04-01',
                    'schema:validThrough' => '2026-10-31',
                ]],
            ]],
        ]));
        $zip->addFromString('objects/0/00/event.json', (string) json_encode([
            '@id' => 'https://data.datatourisme.fr/10/event',
            '@type' => ['EntertainmentAndEvent', 'Festival'],
            'rdfs:label' => ['fr' => ['Festival test']],
            'foaf:homepage' => ['https://festival.test'],
            'schema:startDate' => ['2026-07-01'],
            'schema:endDate' => ['2026-07-03'],
            'isLocatedAt' => [['schema:geo' => ['schema:latitude' => '45.0', 'schema:longitude' => '5.0']]],
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

        // The COPY column list must name `website`, otherwise the column the DDL
        // creates stays NULL for every row.
        $joined = array_map(static fn (array $c): string => implode(' ', $c), $this->captured);
        self::assertTrue(
            (bool) array_filter($joined, static fn (string $c): bool => str_contains(
                $c,
                '\copy tourism_staging.cultural_pois (id, name, category, opening_hours, description, website, wikidata, tags, geom)',
            )),
            'cultural_pois is copied with its website column',
        );
        self::assertTrue(
            (bool) array_filter($joined, static fn (string $c): bool => str_contains(
                $c,
                '\copy tourism_staging.food_pois (id, name, category, opening_hours, description, website, wikidata, tags, geom)',
            )),
            'food_pois is copied with its website column',
        );

        $cultural = explode("\t", rtrim((string) file_get_contents($this->workDir.'/tourism-cultural_pois.copy'), "\n"));
        self::assertSame('Apr-Oct', $cultural[3], 'opening_hours comes from the flux, not a hardcoded null');
        self::assertSame('https://musee.test', $cultural[5], 'website is fed from the contact homepage');
        self::assertSame([
            'type' => ['CulturalSite', 'Museum'],
            'opening_hours' => 'Apr-Oct',
            'website' => 'https://musee.test',
            'phone' => '+33 3 88 00 00 00',
        ], json_decode($cultural[7], true), 'tags keeps more than the type list');

        $event = explode("\t", rtrim((string) file_get_contents($this->workDir.'/tourism-events.copy'), "\n"));
        self::assertSame('https://festival.test', $event[5], 'events.url is populated instead of always null');
    }

    /**
     * tourism.accommodations was the poorest table of the schema: no website, no
     * phone, no opening_hours, no wikidata, so no curated lodging could ever expose
     * a link and none of them could be Wikidata-enriched (#872).
     */
    #[Test]
    public function loadsTheAccommodationContactColumns(): void
    {
        $zipPath = $this->workDir.'/lodging.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('objects/0/00/camping.json', (string) json_encode([
            '@id' => 'https://data.datatourisme.fr/10/camping',
            '@type' => ['Accommodation', 'Camping'],
            'rdfs:label' => ['fr' => ['Camping du Lac']],
            'owl:sameAs' => ['https://www.wikidata.org/entity/Q1234'],
            // Schema-less on purpose: the stored value must be absolutised.
            'foaf:homepage' => ['www.camping-du-lac.test'],
            'hasContact' => [[
                '@type' => ['Agent'],
                'schema:telephone' => ['+33 3 88 00 00 00'],
            ]],
            'isLocatedAt' => [[
                '@type' => ['PlaceOfInterest'],
                'schema:geo' => ['schema:latitude' => '48.5', 'schema:longitude' => '2.3'],
                'schema:openingHoursSpecification' => [[
                    '@type' => ['schema:OpeningHoursSpecification'],
                    'schema:validFrom' => '2026-04-01',
                    'schema:validThrough' => '2026-10-31',
                ]],
            ]],
        ]));
        // A homepage no browser can open must be stored NULL, not as is.
        $zip->addFromString('objects/0/00/hotel.json', (string) json_encode([
            '@id' => 'https://data.datatourisme.fr/10/hotel',
            '@type' => ['Accommodation', 'Hotel'],
            'rdfs:label' => ['fr' => ['Hotel du Parc']],
            'foaf:homepage' => ['nous contacter'],
            'isLocatedAt' => [['schema:geo' => ['schema:latitude' => '48.6', 'schema:longitude' => '2.4']]],
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

        $joined = array_map(static fn (array $c): string => implode(' ', $c), $this->captured);
        self::assertTrue(
            (bool) array_filter($joined, static fn (string $c): bool => str_contains(
                $c,
                '\copy tourism_staging.accommodations (id, name, category, capacity, price, description, opening_hours, website, phone, wikidata, tags, geom)',
            )),
            'accommodations is copied with its contact columns',
        );

        $rows = array_map(
            static fn (string $line): array => explode("\t", $line),
            explode("\n", rtrim((string) file_get_contents($this->workDir.'/tourism-accommodations.copy'), "\n")),
        );
        self::assertCount(2, $rows);

        [$camping, $hotel] = $rows;
        self::assertSame('Apr-Oct', $camping[6]);
        self::assertSame('https://www.camping-du-lac.test', $camping[7], 'a schema-less homepage is absolutised');
        self::assertSame('+33 3 88 00 00 00', $camping[8]);
        self::assertSame('Q1234', $camping[9], 'the Q-ID reaches the column the enrichment pass joins on');

        self::assertSame('\N', $hotel[7], 'an unusable homepage is stored NULL rather than as is');
    }

    /**
     * The staging DDL and the live-schema migrations must describe the same
     * tourism.accommodations, or the atomic swap silently changes the table the API
     * reads. Asserted rather than eyeballed (#872).
     *
     * The migrations live in the API package, which is not mounted in the
     * provisioner container; CI runs this suite from the repository root, where it
     * is the real gate.
     */
    #[Test]
    public function theStagingDdlMatchesTheAccommodationMigrations(): void
    {
        $migrationsDir = __DIR__.'/../../api/migrations';
        if (!is_dir($migrationsDir)) {
            self::markTestSkipped('api/migrations is not reachable from here (provisioner container mounts ./provisioner alone)');
        }

        $reflection = new \ReflectionClass(DataTourismeImporter::class);
        /** @var array<string, string> $stagingDdl */
        $stagingDdl = $reflection->getConstant('STAGING_DDL');
        $staging = $this->columnNames($stagingDdl['accommodations']);

        $migrated = [];
        foreach (glob($migrationsDir.'/Version*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            if (!str_contains($source, 'tourism.accommodations')) {
                continue;
            }

            if (1 === preg_match('/CREATE TABLE IF NOT EXISTS tourism\.accommodations \((.*?)\n\s*\)/s', $source, $matches)) {
                $migrated = array_merge($migrated, $this->columnNames($matches[1]));
            }

            if (!str_contains($source, 'ALTER TABLE tourism.accommodations ADD COLUMN')) {
                continue;
            }

            // Contract with the migration: the added columns are declared in a
            // `COLUMNS` constant so this test can read them back.
            self::assertSame(1, preg_match('/const array COLUMNS = \[(.*?)\];/s', $source, $matches), \sprintf('%s alters tourism.accommodations but does not list its columns in a COLUMNS constant', basename($file)));
            preg_match_all("/'([a-z_]+)'/", $matches[1], $names);
            $migrated = array_merge($migrated, $names[1]);
        }

        sort($staging);
        sort($migrated);
        self::assertSame($migrated, $staging, 'the provisioner staging DDL and the Doctrine migrations describe a different tourism.accommodations');
    }

    /**
     * Column names of a comma-separated column-definition list, parenthesised
     * type arguments (`numeric(10, 2)`, `geometry(Point, 4326)`) removed first so
     * their commas do not split a definition, and table constraints skipped.
     *
     * @return list<string>
     */
    private function columnNames(string $definitions): array
    {
        $flattened = (string) preg_replace('/\([^)]*\)/', '', $definitions);

        $names = [];
        foreach (explode(',', $flattened) as $definition) {
            if (1 === preg_match('/^\s*([a-z_]+)\s/', $definition, $matches)) {
                $names[] = $matches[1];
            }
        }

        return $names;
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
