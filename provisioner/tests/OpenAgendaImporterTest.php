<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Provisioner\Exception\ImportFailedException;
use Provisioner\OpenAgendaImporter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Process\Process;

final class OpenAgendaImporterTest extends TestCase
{
    private string $workDir;

    /**
     * @var list<list<string>>
     */
    private array $captured = [];

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir().'/oa-importer-'.uniqid('', true);
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
     * A JSONL export with a festival (kept), an undated record and a linkless record
     * (both dropped by the mapper).
     */
    private function jsonlBytes(): string
    {
        $lines = [
            (string) json_encode([
                'uid' => 12345,
                'canonicalurl' => 'https://openagenda.com/events/festival-test',
                'title_fr' => 'Festival test',
                'keywords_fr' => ['Festival', 'Musique'],
                'firstdate_begin' => '2026-07-01T18:00:00+02:00',
                'lastdate_end' => '2026-07-03T23:00:00+02:00',
                'location_coordinates' => ['lat' => 48.11, 'lon' => -1.68],
                'location_city' => 'Rennes',
            ]),
            // No canonicalurl → dropped (a rider cannot open it).
            (string) json_encode([
                'uid' => 2,
                'title_fr' => 'Sans lien',
                'firstdate_begin' => '2026-07-01',
                'lastdate_end' => '2026-07-02',
                'location_coordinates' => ['lat' => 48.0, 'lon' => -1.0],
            ]),
            // No dates → dropped (cannot match a stage day).
            (string) json_encode([
                'uid' => 3,
                'canonicalurl' => 'https://openagenda.com/events/undated',
                'title_fr' => 'Sans date',
                'location_coordinates' => ['lat' => 48.0, 'lon' => -1.0],
            ]),
        ];

        return implode("\n", $lines)."\n";
    }

    private function capturingFactory(bool $zoneOpen = true): \Closure
    {
        return function (array $command) use ($zoneOpen): Process {
            /** @var list<string> $cmd */
            $cmd = $command;
            $this->captured[] = $cmd;

            if (1 === preg_match("/TO '([^']+)'/", implode(' ', $cmd), $matches) && str_contains($matches[1], 'zone-geometry')) {
                file_put_contents($matches[1], $zoneOpen ? "1\n" : "0\n");
            }

            return new Process(['true']);
        };
    }

    #[Test]
    public function streamsTheJsonlExportIntoStagingThenPromotes(): void
    {
        $importer = new OpenAgendaImporter(
            exportUrl: 'https://public.opendatasoft.com/api/explore/v2.1/catalog/datasets/evenements-publics-openagenda/exports/jsonl',
            httpClient: new MockHttpClient(new MockResponse($this->jsonlBytes())),
            processFactory: $this->capturingFactory(),
        );

        self::assertTrue($importer->run($this->workDir, 'bretagne'));

        // 1 staging DDL + 1 \copy + 1 GiST index + 1 zone-geometry read
        // + 1 report DDL + 1 promotion + 1 staging drop.
        self::assertCount(7, $this->captured);

        $joined = array_map(static fn (array $c): string => implode(' ', $c), $this->captured);

        $ddl = $joined[0];
        self::assertStringContainsString('CREATE SCHEMA openagenda_staging_bretagne', $ddl);
        self::assertStringContainsString('CREATE TABLE openagenda_staging_bretagne.events', $ddl);
        self::assertStringContainsString("source text NOT NULL DEFAULT 'openagenda'", $ddl);

        self::assertTrue(
            (bool) array_filter($joined, static fn (string $c): bool => str_contains(
                $c,
                '\copy openagenda_staging_bretagne.events (id, name, category, start_date, end_date, url, description, price_min, source, tags, geom)',
            )),
            'events are copied with the source column in the column list',
        );

        // Clipped to the zone, restricted to ids the live table lacks, source stamped.
        $promotion = array_values(array_filter($joined, static fn (string $c): bool => str_contains($c, '--single-transaction')));
        self::assertCount(1, $promotion);
        self::assertStringContainsString('ST_Covers', $promotion[0], 'the national export is clipped to the zone');
        self::assertStringContainsString("'openagenda'", $promotion[0], 'the promotion report is keyed to the openagenda source');

        // The live tourism schema is never dropped nor renamed; only the staging schema is.
        $last = end($this->captured);
        self::assertNotFalse($last);
        self::assertStringContainsString('DROP SCHEMA IF EXISTS openagenda_staging_bretagne CASCADE', implode(' ', $last));
        foreach ($joined as $command) {
            self::assertStringNotContainsString('DROP SCHEMA IF EXISTS tourism CASCADE', $command);
            self::assertStringNotContainsString('RENAME TO tourism', $command);
        }
    }

    #[Test]
    public function writesOnlyTheLinkedDatedRowsWithSourceOpenagenda(): void
    {
        $importer = new OpenAgendaImporter(
            exportUrl: 'https://public.opendatasoft.com/x',
            httpClient: new MockHttpClient(new MockResponse($this->jsonlBytes())),
            processFactory: $this->capturingFactory(),
        );

        $importer->run($this->workDir, 'bretagne');

        $events = (string) file_get_contents($this->workDir.'/openagenda-events.copy');
        self::assertSame(1, substr_count($events, "\n"), 'only the linked, dated festival is written');

        $row = explode("\t", rtrim($events, "\n"));
        self::assertSame('openagenda:12345', $row[0], 'id is namespaced to the source');
        self::assertSame('Festival test', $row[1]);
        self::assertSame('festival', $row[2], 'keywords map onto the shared event vocabulary');
        self::assertSame('2026-07-01', $row[3], 'the date part is extracted from the ISO datetime');
        self::assertSame('2026-07-03', $row[4]);
        self::assertSame('https://openagenda.com/events/festival-test', $row[5]);
        self::assertSame('openagenda', $row[8], 'the source column is stamped openagenda');
        self::assertStringContainsString('SRID=4326;POINT(-1.6800000 48.1100000)', $events);
        self::assertStringContainsString('Rennes', $events, 'the city is preserved in tags');
    }

    #[Test]
    public function skipsPromotionWhenTheZoneHasNoGeometry(): void
    {
        // Same precondition as DataTourisme (#885): no registry geometry, nothing to clip
        // against, so the run reports a skip rather than promoting a whole country's events.
        $importer = new OpenAgendaImporter(
            exportUrl: 'https://public.opendatasoft.com/x',
            httpClient: new MockHttpClient(new MockResponse($this->jsonlBytes())),
            processFactory: $this->capturingFactory(zoneOpen: false),
        );

        self::assertFalse($importer->run($this->workDir, 'bretagne'));

        $joined = array_map(static fn (array $c): string => implode(' ', $c), $this->captured);
        self::assertSame([], array_values(array_filter($joined, static fn (string $c): bool => str_contains($c, '--single-transaction'))), 'nothing is promoted');
        $last = end($this->captured);
        self::assertNotFalse($last);
        self::assertStringContainsString('DROP SCHEMA IF EXISTS openagenda_staging_bretagne CASCADE', implode(' ', $last), 'the staging schema is still cleaned up');
    }

    #[Test]
    public function escapesSpecialCharactersInCopyFields(): void
    {
        // A title with tabs/newlines would split or break the COPY row and abort the load
        // under ON_ERROR_STOP=1.
        $line = (string) json_encode([
            'uid' => 'abc',
            'canonicalurl' => 'https://openagenda.com/e/1',
            'title_fr' => "Name\twith\ttabs\nand newline\\backslash",
            'keywords_fr' => ['Concert'],
            'firstdate_begin' => '2026-08-01',
            'lastdate_end' => '2026-08-01',
            'location_coordinates' => ['lat' => 48.0, 'lon' => -1.0],
        ]);

        $importer = new OpenAgendaImporter(
            exportUrl: 'https://public.opendatasoft.com/x',
            httpClient: new MockHttpClient(new MockResponse($line."\n")),
            processFactory: $this->capturingFactory(),
        );
        $importer->run($this->workDir, 'bretagne');

        $events = (string) file_get_contents($this->workDir.'/openagenda-events.copy');
        self::assertStringNotContainsString("\t\t", $events, 'a literal tab would split into extra columns');
        self::assertStringContainsString('\t', $events, 'tab escaped as backslash-t');
        self::assertStringContainsString('\n', $events, 'newline escaped as backslash-n');
        self::assertStringContainsString('\\\\', $events, 'backslash escaped as double backslash');
    }

    #[Test]
    public function downloadFailureRaisesImportFailedException(): void
    {
        $importer = new OpenAgendaImporter(
            exportUrl: 'https://public.opendatasoft.com/x',
            httpClient: new MockHttpClient(new MockResponse('not found', ['http_code' => 404])),
            processFactory: $this->capturingFactory(),
        );

        $this->expectException(ImportFailedException::class);
        $importer->run($this->workDir, 'bretagne');
    }
}
