<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Provisioner\Exception\ImportFailedException;
use Provisioner\RoutingPerimeter;
use Symfony\Component\Process\Process;

final class RoutingPerimeterTest extends TestCase
{
    private string $tilesDir;

    protected function setUp(): void
    {
        $this->tilesDir = sys_get_temp_dir().'/routing-perimeter-'.uniqid('', true);
        mkdir($this->tilesDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tilesDir.'/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->tilesDir)) {
            rmdir($this->tilesDir);
        }
    }

    private function extract(string $slug, string $contents = 'pbf'): void
    {
        file_put_contents(\sprintf('%s/%s-latest.osm.pbf', $this->tilesDir, $slug), $contents);
    }

    #[Test]
    public function readsThePerimeterFromTheExtractsInTheRoutingVolume(): void
    {
        // The perimeter is an *observation* of what valhalla_build_tiles builds from
        // (every extract present in the volume), not a second list to keep in step.
        $this->extract('france');
        $this->extract('belgium');

        $perimeter = new RoutingPerimeter($this->tilesDir);

        self::assertTrue($perimeter->isObservable());
        self::assertSame(['belgium', 'france'], $perimeter->slugs());
        self::assertTrue($perimeter->covers('france'));
        self::assertFalse($perimeter->covers('netherlands'));
    }

    #[Test]
    public function ignoresZeroByteLeftovers(): void
    {
        // The build script deletes empty extracts before building (they were the
        // mountpoint Docker created for the removed default.osm.pbf bind mount), so an
        // empty file is not in the graph either.
        $this->extract('france', '');
        $this->extract('belgium');

        self::assertSame(['belgium'], new RoutingPerimeter($this->tilesDir)->slugs());
    }

    #[Test]
    public function anEmptyVolumeIsAnObservedEmptyPerimeter(): void
    {
        // Observed and empty: a machine with no graph yet, which must refuse to open a
        // zone rather than silently produce an unrouteable one.
        $perimeter = new RoutingPerimeter($this->tilesDir);

        self::assertTrue($perimeter->isObservable());
        self::assertSame([], $perimeter->slugs());
        self::assertFalse($perimeter->covers('france'));
    }

    #[Test]
    public function anAbsentVolumeIsNotObservable(): void
    {
        // Distinct from an empty one: a missing mount must not turn into a provisioning
        // outage, so the caller warns instead of refusing.
        self::assertFalse(new RoutingPerimeter($this->tilesDir.'/nope')->isObservable());
    }

    #[Test]
    public function recordsTheObservedPerimeterAndPrunesWhatIsGone(): void
    {
        $this->extract('france');

        /** @var list<list<string>> $captured */
        $captured = [];
        new RoutingPerimeter($this->tilesDir, function (array $command) use (&$captured): Process {
            /** @var list<string> $cmd */
            $cmd = $command;
            $captured[] = $cmd;

            return new Process(['true']);
        })->record();

        self::assertCount(1, $captured);
        self::assertSame('psql', $captured[0][0]);
        self::assertContains('--single-transaction', $captured[0]);

        $sql = $this->sqlOf($captured[0]);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS osm.routing_perimeter', $sql);
        self::assertStringContainsString("('france', now())", $sql);
        self::assertStringContainsString('ON CONFLICT (slug) DO UPDATE SET observed_at = excluded.observed_at', $sql);
        // A country removed from the volume is no longer in the graph, so it must leave
        // the table too — otherwise the containment check would pass on a stale row.
        self::assertStringContainsString("DELETE FROM osm.routing_perimeter WHERE slug NOT IN ('france')", $sql);
    }

    #[Test]
    public function recordingAnEmptyPerimeterClearsTheTable(): void
    {
        /** @var list<list<string>> $captured */
        $captured = [];
        new RoutingPerimeter($this->tilesDir, function (array $command) use (&$captured): Process {
            /** @var list<string> $cmd */
            $cmd = $command;
            $captured[] = $cmd;

            return new Process(['true']);
        })->record();

        $sql = $this->sqlOf($captured[0]);
        self::assertStringContainsString('DELETE FROM osm.routing_perimeter;', $sql);
        self::assertStringNotContainsString('INSERT INTO osm.routing_perimeter', $sql);
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

    #[Test]
    public function aFailedRecordingRaisesImportFailedExceptionWithStderr(): void
    {
        $this->extract('france');

        $perimeter = new RoutingPerimeter(
            $this->tilesDir,
            static fn (array $command): Process => new Process(['sh', '-c', 'echo "boom" 1>&2; exit 4']),
        );

        try {
            $perimeter->record();
            self::fail('Expected ImportFailedException');
        } catch (ImportFailedException $importFailedException) {
            self::assertStringContainsString('psql record routing perimeter failed', $importFailedException->getMessage());
            self::assertStringContainsString('boom', $importFailedException->getMessage());
            self::assertStringContainsString('exit 4', $importFailedException->getMessage());
        }
    }
}
