<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Provisioner\Exception\ImportFailedException;
use Provisioner\OverrideImporter;
use Symfony\Component\Process\Process;

final class OverrideImporterTest extends TestCase
{
    private string $workDir;

    /**
     * @var list<list<string>>
     */
    private array $captured = [];

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir().'/override-'.uniqid('', true);
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

    private function importer(): OverrideImporter
    {
        return new OverrideImporter(function (array $command): Process {
            /** @var list<string> $cmd */
            $cmd = $command;
            $this->captured[] = $cmd;

            return new Process(['true']);
        });
    }

    private function file(string $contents): string
    {
        $path = $this->workDir.'/override.tsv';
        file_put_contents($path, $contents);

        return $path;
    }

    private function sql(): string
    {
        self::assertCount(1, $this->captured, 'exactly one psql call, so the import is one transaction');
        $sql = end($this->captured[0]);
        self::assertIsString($sql);

        return $sql;
    }

    #[Test]
    public function importsAnOsmCorrectionIntoTheLiveTable(): void
    {
        $rows = $this->importer()->import(
            $this->file("osm\tN/42\tcamp_site\t48.5\t2.5\tCamping du Moulin\n"),
            'bretagne',
        );

        self::assertSame(1, $rows);

        $sql = $this->sql();
        self::assertStringContainsString('INSERT INTO osm.accommodations', $sql);
        self::assertStringContainsString("('N', 42, 'Camping du Moulin', 'camp_site'", $sql);
        self::assertStringContainsString('ST_SetSRID(ST_MakePoint(2.5000000, 48.5000000), 4326)', $sql);
        self::assertStringContainsString("'bretagne'", $sql, 'the row carries the zone that corrected it');
        self::assertContains('--single-transaction', $this->captured[0]);
    }

    #[Test]
    public function importsADataTourismeCorrectionIntoItsOwnTable(): void
    {
        $this->importer()->import(
            $this->file("datatourisme\tFR-123\thotel\t48.5\t2.5\tHotel du Parc\n"),
            'bretagne',
        );

        $sql = $this->sql();
        self::assertStringContainsString('INSERT INTO tourism.accommodations', $sql);
        self::assertStringContainsString("('FR-123', 'Hotel du Parc', 'hotel'", $sql);
        self::assertStringNotContainsString('INSERT INTO osm.accommodations', $sql);
    }

    #[Test]
    public function carriesTheOptionalColumnsWhenTheOperatorSuppliesThem(): void
    {
        $this->importer()->import(
            $this->file("osm\tN/42\tcamp_site\t48.5\t2.5\tCamping du Moulin\thttps://moulin.test\tAu bord de l'eau\tApr-Oct\n"),
            'bretagne',
        );

        $sql = $this->sql();
        self::assertStringContainsString("'https://moulin.test'", $sql);
        // Quotes in free text must not end the literal.
        self::assertStringContainsString("'Au bord de l''eau'", $sql);
        self::assertStringContainsString("'Apr-Oct'", $sql);
    }

    #[Test]
    public function leavesOmittedOptionalColumnsNull(): void
    {
        $this->importer()->import(
            $this->file("osm\tN/42\tcamp_site\t48.5\t2.5\tCamping du Moulin\n"),
            'bretagne',
        );

        self::assertStringContainsString('NULL, NULL, NULL', $this->sql());
    }

    #[Test]
    public function neverOverwritesARowTheIndexAlreadyHolds(): void
    {
        // Append-only holds here too (ADR-049 §5): an override adds what the gate refused, it
        // does not rewrite what is already imported. An operator wanting to change a live
        // value cannot do it with this file, deliberately — the alternative is a mechanism
        // that can silently overwrite the sources.
        $this->importer()->import($this->file("osm\tN/42\tcamp_site\t48.5\t2.5\tCamping\n"), 'bretagne');

        self::assertStringContainsString('ON CONFLICT (osm_type, osm_id) DO NOTHING', $this->sql());
    }

    #[Test]
    public function skipsTheHeaderRejectedTsvWrites(): void
    {
        // So the operator can edit rejected.tsv in place rather than reformatting it.
        $rows = $this->importer()->import(
            $this->file("source\tsource_id\tcategory\tlat\tlon\tname\nosm\tN/42\tcamp_site\t48.5\t2.5\tCamping\n"),
            'bretagne',
        );

        self::assertSame(1, $rows);
    }

    #[Test]
    public function importsEveryRowInOneStatementPerTable(): void
    {
        $rows = $this->importer()->import(
            $this->file(
                "osm\tN/42\tcamp_site\t48.5\t2.5\tCamping A\n".
                "osm\tW/43\thotel\t48.6\t2.6\tHotel B\n".
                "datatourisme\tFR-1\thotel\t48.7\t2.7\tHotel C\n",
            ),
            'bretagne',
        );

        self::assertSame(3, $rows);
        $sql = $this->sql();
        self::assertSame(1, substr_count($sql, 'INSERT INTO osm.accommodations'));
        self::assertSame(1, substr_count($sql, 'INSERT INTO tourism.accommodations'));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function malformedProvider(): iterable
    {
        yield 'too few fields' => ["osm\tN/42\tcamp_site\n", 'expected 6 to 9'];
        yield 'too many fields' => ["osm\tN/42\tcamp_site\t48.5\t2.5\tA\tb\tc\td\te\n", 'expected 6 to 9'];
        yield 'unknown source' => ["overpass\tN/42\tcamp_site\t48.5\t2.5\tA\n", 'expected osm or datatourisme'];
        yield 'malformed osm id' => ["osm\t42\tcamp_site\t48.5\t2.5\tA\n", 'expected the N/123 form'];
        yield 'non-numeric coordinates' => ["osm\tN/42\tcamp_site\tnorth\t2.5\tA\n", 'non-numeric coordinates'];
        yield 'coordinates off the planet' => ["osm\tN/42\tcamp_site\t91.0\t2.5\tA\n", 'outside the world'];
        yield 'empty name' => ["osm\tN/42\tcamp_site\t48.5\t2.5\t\n", 'empty name'];
        yield 'empty category' => ["osm\tN/42\t\t48.5\t2.5\tA\n", 'empty category'];
    }

    #[Test]
    #[DataProvider('malformedProvider')]
    public function refusesAMalformedFileWholeWithoutInsertingAnything(string $contents, string $expected): void
    {
        try {
            $this->importer()->import($this->file($contents), 'bretagne');
            self::fail('Expected ImportFailedException');
        } catch (ImportFailedException $importFailedException) {
            self::assertStringContainsString($expected, $importFailedException->getMessage());
        }

        // The parse runs to completion before any statement, so a file the operator got half
        // right leaves the index exactly as it was.
        self::assertSame([], $this->captured, 'nothing was inserted');
    }

    #[Test]
    public function namesTheOffendingLineSoTheOperatorCanFixIt(): void
    {
        try {
            $this->importer()->import(
                $this->file(
                    "osm\tN/42\tcamp_site\t48.5\t2.5\tCamping A\n".
                    "osm\tN/43\tcamp_site\t48.6\tnorth\tCamping B\n",
                ),
                'bretagne',
            );
            self::fail('Expected ImportFailedException');
        } catch (ImportFailedException $importFailedException) {
            self::assertStringContainsString('line 2', $importFailedException->getMessage());
        }

        self::assertSame([], $this->captured, 'the first, valid row was not inserted either');
    }

    #[Test]
    public function refusesAnEmptyOrMissingFile(): void
    {
        try {
            $this->importer()->import($this->file("\n\n"), 'bretagne');
            self::fail('Expected ImportFailedException');
        } catch (ImportFailedException $importFailedException) {
            self::assertStringContainsString('no data row', $importFailedException->getMessage());
        }

        try {
            $this->importer()->import($this->workDir.'/absent.tsv', 'bretagne');
            self::fail('Expected ImportFailedException');
        } catch (ImportFailedException $importFailedException) {
            self::assertStringContainsString('does not exist', $importFailedException->getMessage());
        }

        self::assertSame([], $this->captured);
    }

    #[Test]
    public function aFailedInsertRaisesImportFailedExceptionWithStderr(): void
    {
        $importer = new OverrideImporter(
            static fn (array $command): Process => new Process(['sh', '-c', 'echo "boom" 1>&2; exit 3']),
        );

        try {
            $importer->import($this->file("osm\tN/42\tcamp_site\t48.5\t2.5\tCamping\n"), 'bretagne');
            self::fail('Expected ImportFailedException');
        } catch (ImportFailedException $importFailedException) {
            self::assertStringContainsString('boom', $importFailedException->getMessage());
            self::assertStringContainsString('exit 3', $importFailedException->getMessage());
        }
    }
}
