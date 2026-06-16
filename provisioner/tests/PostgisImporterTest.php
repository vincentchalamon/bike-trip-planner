<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Provisioner\Exception\ImportFailedException;
use Provisioner\PostgisImporter;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class PostgisImporterTest extends TestCase
{
    /**
     * @var list<list<string>>
     */
    private array $captured = [];

    /**
     * Factory that records each built command and runs a trivial successful process.
     */
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
    public function filterBuildsOsmiumTagsFilterCommand(): void
    {
        $importer = new PostgisImporter(
            flexStylePath: '/app/osm2pgsql/tier1.lua',
            processFactory: $this->capturingFactory(),
        );

        $importer->filter('/data/default.osm.pbf', '/data/tier1-filtered.osm.pbf');

        self::assertCount(1, $this->captured);
        self::assertSame(
            ['osmium', 'tags-filter', '--overwrite', '-o', '/data/tier1-filtered.osm.pbf', '/data/default.osm.pbf'],
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
        // Country boundaries (relations) for the admin_boundaries table.
        self::assertContains('r/admin_level=2', $this->captured[0]);
        // Signed cycle route relations for the cycle_routes table.
        self::assertContains('r/route=bicycle', $this->captured[0]);
    }

    #[Test]
    public function importCreatesStagingSchemaThenRunsOsm2pgsqlFlex(): void
    {
        $importer = new PostgisImporter(
            flexStylePath: '/app/osm2pgsql/tier1.lua',
            cacheMb: 512,
            processFactory: $this->capturingFactory(),
        );

        $importer->import('/data/tier1-filtered.osm.pbf');

        self::assertCount(2, $this->captured);

        self::assertSame('psql', $this->captured[0][0]);
        self::assertStringContainsString('CREATE SCHEMA osm_staging', implode(' ', $this->captured[0]));

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
        self::assertSame('osm_staging', $osm2pgsql[$midIdx + 1], '--middle-schema value must match STAGING_SCHEMA');
        self::assertContains('/data/tier1-filtered.osm.pbf', $osm2pgsql);
    }

    #[Test]
    public function buildDerivedCreatesCoveragePolygonAndMetadataInStaging(): void
    {
        $importer = new PostgisImporter(
            flexStylePath: '/app/osm2pgsql/tier1.lua',
            processFactory: $this->capturingFactory(),
        );

        $importer->buildDerived();

        self::assertCount(2, $this->captured);

        $coverage = implode(' ', $this->captured[0]);
        self::assertStringContainsString('CREATE TABLE osm_staging.coverage AS', $coverage);
        self::assertStringContainsString('ST_Multi(ST_Union(geom))', $coverage);
        self::assertStringContainsString('WHERE admin_level = 2', $coverage);
        self::assertStringContainsString('USING gist (geom)', $coverage);

        $metadata = implode(' ', $this->captured[1]);
        self::assertStringContainsString('CREATE TABLE osm_staging.metadata AS', $metadata);
        self::assertStringContainsString('now() AS refreshed_at', $metadata);
        // Counts cover every flex feature table so /health reports each one.
        self::assertStringContainsString("'pois', (SELECT count(*) FROM osm_staging.pois)", $metadata);
        self::assertStringContainsString("'admin_boundaries', (SELECT count(*) FROM osm_staging.admin_boundaries)", $metadata);
        self::assertStringContainsString("'cycle_routes', (SELECT count(*) FROM osm_staging.cycle_routes)", $metadata);
    }

    #[Test]
    public function swapRenamesStagingOntoLiveInOneTransaction(): void
    {
        $importer = new PostgisImporter(
            flexStylePath: '/app/osm2pgsql/tier1.lua',
            liveSchema: 'osm',
            processFactory: $this->capturingFactory(),
        );

        $importer->swap();

        self::assertCount(1, $this->captured);
        $cmd = $this->captured[0];
        self::assertSame('psql', $cmd[0]);
        self::assertContains('--single-transaction', $cmd);

        $sql = end($cmd);
        self::assertStringContainsString('DROP SCHEMA IF EXISTS osm CASCADE', $sql);
        self::assertStringContainsString('ALTER SCHEMA osm_staging RENAME TO osm', $sql);
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
        // A real process that outlives the (tiny) timeout, mirroring the
        // merge-timeout test in OsmDataDownloaderTest. Process::run() is @final,
        // so it cannot be overridden to fake the timeout.
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
