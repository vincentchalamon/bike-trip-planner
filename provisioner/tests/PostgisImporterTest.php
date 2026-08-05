<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Provisioner\Exception\ImportFailedException;
use Provisioner\PostgisImporter;
use Provisioner\WikidataEnricher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class PostgisImporterTest extends TestCase
{
    /**
     * @var list<list<string>>
     */
    private array $captured = [];

    /**
     * The Process instances handed back, in command order, so the env the importer sets
     * on them can be asserted after the fact.
     *
     * @var list<Process>
     */
    private array $envProbes = [];

    /**
     * Factory that records each built command and runs a trivial successful process.
     */
    private function capturingFactory(): \Closure
    {
        return function (array $command): Process {
            /** @var list<string> $cmd */
            $cmd = $command;
            $this->captured[] = $cmd;
            $process = new Process(['true']);
            $this->envProbes[] = $process;

            return $process;
        };
    }

    #[Test]
    public function filterBuildsOsmiumTagsFilterCommand(): void
    {
        $importer = new PostgisImporter(
            flexStylePath: '/app/osm2pgsql/tier1.lua',
            processFactory: $this->capturingFactory(),
        );

        $importer->filter('/data/regions/bretagne-latest.osm.pbf', '/data/tier1-filtered.osm.pbf');

        self::assertCount(1, $this->captured);
        self::assertSame(
            ['osmium', 'tags-filter', '--overwrite', '-o', '/data/tier1-filtered.osm.pbf', '/data/regions/bretagne-latest.osm.pbf'],
            \array_slice($this->captured[0], 0, 6),
        );
        self::assertContains('nwr/man_made=water_tap', $this->captured[0]);
        self::assertContains('nwr/natural=spring', $this->captured[0]);

        // Categories added by later cut-over slices must stay in the filter, else
        // their tables import empty (tier1.lua maps them but osmium would drop them).
        self::assertContains('nwr/railway=station', $this->captured[0]);
        self::assertContains('nwr/service:bicycle:repair=yes', $this->captured[0]);
        $joined = implode(' ', $this->captured[0]);
        self::assertStringContainsString('hospital', $joined);
        self::assertStringContainsString('bicycle_repair_station', $joined);
        self::assertStringContainsString('charging_station', $joined);
        self::assertStringContainsString(',fuel,', $joined);
        self::assertStringContainsString('historic=', $joined);
        self::assertStringContainsString('attraction,museum', $joined);
        self::assertStringContainsString('farm,bicycle', $joined);
        self::assertStringContainsString('w/highway=', $joined);
        // Administrative boundaries (relations) for the admin_boundaries table:
        // countries, regions, departments and communes — the commune polygons back
        // the offline locality labels (#880), so keeping only level 2 would leave
        // stage endpoints unlabelled.
        self::assertContains('r/admin_level=2,4,6,8', $this->captured[0]);
        // Signed cycle route relations for the cycle_routes table.
        self::assertContains('r/route=bicycle', $this->captured[0]);
        // Ferry crossings (ways + route relations) for the ferries table.
        self::assertContains('w/route=ferry', $this->captured[0]);
        self::assertContains('r/route=ferry', $this->captured[0]);
        // Fords (nodes + ways) for the fords table.
        self::assertContains('nw/ford', $this->captured[0]);
    }

    #[Test]
    public function stagingSchemaIsDerivedFromTheZone(): void
    {
        // Derived, never configured: the value cannot drift from the one the flex style
        // receives, which is what the former fixed constant existed to guarantee.
        self::assertSame('osm_staging_bretagne', PostgisImporter::stagingSchema('bretagne'));
        self::assertSame('osm_staging_nord_pas_de_calais', PostgisImporter::stagingSchema('nord-pas-de-calais'));
        self::assertSame('osm_staging_provence_alpes_cote_d_azur', PostgisImporter::stagingSchema('provence-alpes-cote-d-azur'));
    }

    #[Test]
    public function importCreatesTheZoneStagingSchemaAndHandsItToTheFlexStyle(): void
    {
        $importer = new PostgisImporter(
            flexStylePath: '/app/osm2pgsql/tier1.lua',
            cacheMb: 512,
            processFactory: $this->capturingFactory(),
        );

        $importer->import('osm_staging_bretagne', '/data/tier1-filtered.osm.pbf');

        self::assertCount(2, $this->captured);

        self::assertSame('psql', $this->captured[0][0]);
        $create = implode(' ', $this->captured[0]);
        self::assertStringContainsString('CREATE SCHEMA osm_staging_bretagne', $create);
        // Only ever the zone's own staging schema: no live schema is named here, so a
        // crashed run can never drop live data.
        self::assertStringContainsString('DROP SCHEMA IF EXISTS osm_staging_bretagne CASCADE', $create);
        self::assertStringNotContainsString('DROP SCHEMA IF EXISTS osm CASCADE', $create);

        $osm2pgsql = $this->captured[1];
        self::assertSame('osm2pgsql', $osm2pgsql[0]);
        self::assertContains('--create', $osm2pgsql);
        self::assertContains('--slim', $osm2pgsql);
        self::assertContains('--drop', $osm2pgsql);
        self::assertContains('--output=flex', $osm2pgsql);
        self::assertContains('/app/osm2pgsql/tier1.lua', $osm2pgsql);
        self::assertContains('512', $osm2pgsql);
        $midIdx = array_search('--middle-schema', $osm2pgsql, true);
        self::assertNotFalse($midIdx, '--middle-schema flag must be present');
        self::assertArrayHasKey($midIdx + 1, $osm2pgsql, '--middle-schema must be followed by its value');
        self::assertSame('osm_staging_bretagne', $osm2pgsql[$midIdx + 1], '--middle-schema value must match the zone staging schema');
        self::assertContains('/data/tier1-filtered.osm.pbf', $osm2pgsql);

        // tier1.lua reads its output schema from this variable (os.getenv), so the style
        // and the promotion cannot disagree on where the tables are.
        self::assertSame(
            ['TIER1_STAGING_SCHEMA' => 'osm_staging_bretagne'],
            $this->envProbes[1]->getEnv(),
        );
        self::assertSame([], $this->envProbes[0]->getEnv(), 'psql needs no style env');
    }

    #[Test]
    public function promoteReplacesTheGlobalSwapWithATransactionalInsert(): void
    {
        $importer = new PostgisImporter(
            flexStylePath: '/app/osm2pgsql/tier1.lua',
            processFactory: $this->capturingFactory(),
        );

        $importer->promote('bretagne', 'Bretagne', 'france', 'osm_staging_bretagne');

        self::assertCount(2, $this->captured);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS provisioner.promotion_report', implode(' ', $this->captured[0]));

        $cmd = $this->captured[1];
        self::assertSame('psql', $cmd[0]);
        self::assertContains('--single-transaction', $cmd);

        $sql = $this->sqlOf($cmd);
        // The destructive step of ADR-040 is gone for good.
        self::assertStringNotContainsString('DROP SCHEMA', $sql);
        self::assertStringNotContainsString('RENAME TO', $sql);
        self::assertStringContainsString('INSERT INTO %1$I.%2$I (%3$s, zone, last_seen_at)', $sql);
    }

    #[Test]
    public function promoteRecordsTheZoneInTheRegistryWithoutLosingItsGeometry(): void
    {
        $importer = new PostgisImporter(
            flexStylePath: '/app/osm2pgsql/tier1.lua',
            processFactory: $this->capturingFactory(),
        );

        $importer->promote('bretagne', 'Bretagne', 'france', 'osm_staging_bretagne');

        $sql = $this->sqlOf($this->captured[1]);

        self::assertStringContainsString('INSERT INTO osm.zones (slug, name, country, opened_at, refreshed_at, pipeline_version, feature_counts, new_entries, geom)', $sql);
        self::assertStringContainsString("'bretagne', 'Bretagne', 'france'", $sql);
        self::assertStringContainsString('ST_Multi(ST_Union(geom))::geometry(MultiPolygon, 4326) FROM osm_staging_bretagne.admin_boundaries', $sql);
        // Re-opening must not lose a geometry a previous run recorded: Geofabrik extracts
        // are clipped, so a re-import can legitimately yield no boundary at all (#880).
        self::assertStringContainsString('geom = COALESCE(excluded.geom, osm.zones.geom)', $sql);
        // opened_at is set once and never touched again by the upsert.
        self::assertStringNotContainsString('opened_at = excluded.opened_at', $sql);
    }

    #[Test]
    public function promoteRebuildsCoverageFromTheRegistryRatherThanFromTheExtract(): void
    {
        $importer = new PostgisImporter(
            flexStylePath: '/app/osm2pgsql/tier1.lua',
            processFactory: $this->capturingFactory(),
        );

        $importer->promote('bretagne', 'Bretagne', 'france', 'osm_staging_bretagne');

        $sql = $this->sqlOf($this->captured[1]);

        self::assertStringContainsString('DELETE FROM osm.coverage;', $sql);
        self::assertStringContainsString('SELECT ST_Multi(ST_Union(geom))::geometry(MultiPolygon, 4326) FROM osm.zones WHERE geom IS NOT NULL', $sql);
        // "Out of zone" must mean "zone not yet opened", so coverage is the union of the
        // opened zones — never of the boundaries that happened to be in one extract.
        self::assertStringNotContainsString('FROM osm_staging_bretagne.admin_boundaries;', $sql);
    }

    #[Test]
    public function promoteRefreshesMetadataAndCompletenessFromTheLiveTables(): void
    {
        $importer = new PostgisImporter(
            flexStylePath: '/app/osm2pgsql/tier1.lua',
            processFactory: $this->capturingFactory(),
        );

        $importer->promote('bretagne', 'Bretagne', 'france', 'osm_staging_bretagne');

        $sql = $this->sqlOf($this->captured[1]);

        self::assertStringContainsString('DELETE FROM osm.metadata;', $sql);
        self::assertStringContainsString('INSERT INTO osm.metadata (refreshed_at, feature_counts, completeness, rejections)', $sql);
        // Counts and ratios describe the whole index, not just the zone that was opened:
        // the swap used to make those two the same thing, promotion does not.
        self::assertStringContainsString("'pois', (SELECT count(*) FROM osm.pois)", $sql);
        self::assertStringContainsString("'fords', (SELECT count(*) FROM osm.fords)", $sql);
        // Everything from the completeness expression onwards: the counts name the same
        // tables, so asserting on the whole statement would prove nothing about either.
        $start = strpos($sql, "'pois', (SELECT jsonb_build_object(");
        self::assertIsInt($start);
        $completeness = substr($sql, $start);
        self::assertStringContainsString("count(nullif(btrim(name), ''))", $completeness);
        self::assertStringContainsString('FROM osm.accommodations GROUP BY category', $completeness);
        // `ways` carries neither name, link nor hours, and is the largest table by an
        // order of magnitude: measuring it would buy nothing but a scan.
        self::assertStringNotContainsString('FROM osm.ways)', $completeness);
        // The completeness gate lands in #884; the column ships empty until then.
        self::assertStringContainsString("'{}'::jsonb", $sql);
    }

    #[Test]
    public function runEnrichesWikidataBearingOsmTablesBeforePromotion(): void
    {
        $workDir = sys_get_temp_dir().'/postgis-enrich-'.uniqid('', true);
        mkdir($workDir, 0o755, true);

        $sparql = new MockHttpClient(new MockResponse((string) json_encode([
            'results' => ['bindings' => [[
                'item' => ['value' => 'http://www.wikidata.org/entity/Q42'],
                'website' => ['value' => 'https://w.test'],
            ]]],
        ])));

        // Empty cache: emulate psql exporting Q42 as the missing Q-ID so the
        // enrichment fetch path runs against the mocked SPARQL endpoint.
        $factory = function (array $command): Process {
            /** @var list<string> $cmd */
            $cmd = $command;
            $this->captured[] = $cmd;
            if (1 === preg_match("/TO '([^']+)'/", implode(' ', $cmd), $matches)) {
                file_put_contents($matches[1], "Q42\n");
            }

            return new Process(['true']);
        };

        $importer = new PostgisImporter(
            flexStylePath: '/app/osm2pgsql/tier1.lua',
            processFactory: $factory,
            enricher: new WikidataEnricher($sparql),
        );

        try {
            $importer->run('bretagne', 'Bretagne', 'france', $workDir.'/bretagne-latest.osm.pbf', $workDir.'/tier1-filtered.osm.pbf');

            $joined = array_map(static fn (array $c): string => implode(' ', $c), $this->captured);
            $has = static fn (string ...$needles): bool => (bool) array_filter(
                $joined,
                static fn (string $c): bool => array_all($needles, static fn (string $n): bool => str_contains($c, $n)),
            );

            self::assertTrue(
                $has('INSERT INTO provisioner.wikidata_candidates', 'SELECT DISTINCT wikidata FROM osm_staging_bretagne.cultural_pois', 'SELECT DISTINCT wikidata FROM osm_staging_bretagne.accommodations'),
                'candidate Q-IDs are collected from the zone staging tables',
            );
            self::assertTrue(
                $has('UPDATE osm_staging_bretagne.cultural_pois t SET', 'FROM provisioner.wikidata_cache c'),
                'cultural_pois is enriched from the cache before promotion',
            );
            self::assertTrue(
                $has('UPDATE osm_staging_bretagne.accommodations t SET', "COALESCE(t.website, c.payload->>'website')", 'FROM provisioner.wikidata_cache c'),
                'accommodations is enriched from the cache, keeping the OSM website',
            );

            $fetch = (string) file_get_contents($workDir.'/wikidata-fetch.copy');
            self::assertStringContainsString('Q42', $fetch);
            self::assertStringContainsString('https://w.test', $fetch);

            // Enrichment (scratch drop) precedes the promotion, which precedes dropping
            // the staging schema: the enrichment must ship with the rows that go live.
            $dropScratch = $this->commandIndex('DROP TABLE IF EXISTS provisioner.wikidata_candidates');
            $promote = $this->commandIndex('INSERT INTO osm.zones');
            self::assertGreaterThan(-1, $dropScratch);
            self::assertGreaterThan($dropScratch, $promote);

            // The staging schema is reclaimed last, and only ever the zone's own.
            $last = end($this->captured);
            self::assertNotFalse($last);
            self::assertSame('DROP SCHEMA IF EXISTS osm_staging_bretagne CASCADE;', $this->sqlOf($last));
        } finally {
            foreach (glob($workDir.'/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($workDir);
        }
    }

    /**
     * The SQL psql was handed, i.e. the last argument of a captured command.
     *
     * @param list<string> $command
     */
    private function sqlOf(array $command): string
    {
        $sql = end($command);
        self::assertIsString($sql);

        return $sql;
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
    public function failingProcessRaisesImportFailedExceptionWithStderr(): void
    {
        $factory = static fn (array $command): Process => new Process(['sh', '-c', 'echo "boom" 1>&2; exit 3']);

        $importer = new PostgisImporter(
            flexStylePath: '/app/osm2pgsql/tier1.lua',
            processFactory: $factory,
        );

        try {
            $importer->filter('/in.osm.pbf', '/out.osm.pbf');
            self::fail('Expected ImportFailedException');
        } catch (ImportFailedException $importFailedException) {
            self::assertStringContainsString('osmium tags-filter failed', $importFailedException->getMessage());
            self::assertStringContainsString('boom', $importFailedException->getMessage());
            self::assertStringContainsString('exit 3', $importFailedException->getMessage());
        }
    }

    #[Test]
    public function timedOutProcessRaisesImportFailedException(): void
    {
        // A real process that outlives the (tiny) timeout. Process::run() is @final, so
        // it cannot be overridden to fake the timeout.
        $importer = new PostgisImporter(
            flexStylePath: '/app/osm2pgsql/tier1.lua',
            processFactory: static fn (array $command): Process => new Process(['sleep', '5']),
            timeoutSeconds: 0.05,
        );

        try {
            $importer->filter('/in.osm.pbf', '/out.osm.pbf');
            self::fail('Expected ImportFailedException');
        } catch (ImportFailedException $importFailedException) {
            self::assertStringContainsString('osmium tags-filter timed out after', $importFailedException->getMessage());
            self::assertInstanceOf(ProcessTimedOutException::class, $importFailedException->getPrevious());
        }
    }
}
