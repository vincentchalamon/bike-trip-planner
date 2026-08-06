<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Provisioner\NameResolver;
use Provisioner\PlaceEnrichmentPass;
use Symfony\Component\Process\Process;

final class PlaceEnrichmentPassTest extends TestCase
{
    private string $workDir;

    /**
     * @var list<list<string>>
     */
    private array $captured = [];

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir().'/place-enrichment-'.uniqid('', true);
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
     * Captures each psql command and, when asked, plays the part of the database by writing
     * the file a `\copy ... TO` would have produced.
     *
     * @param list<string> $candidateLines rows the export hands back, tab-separated:
     *                                     source_id, category, tags json, wikidata label, locality
     */
    private function pass(array $candidateLines = [], string $rejectedCount = '0'): PlaceEnrichmentPass
    {
        return new PlaceEnrichmentPass(
            source: 'osm',
            identity: "a.osm_type || '/' || a.osm_id",
            exemptCategories: ['shelter'],
            processFactory: function (array $command) use ($candidateLines, $rejectedCount): Process {
                /** @var list<string> $cmd */
                $cmd = $command;
                $this->captured[] = $cmd;
                $sql = end($cmd);

                if (\is_string($sql) && 1 === preg_match("/TO '([^']+)'/", $sql, $matches)) {
                    file_put_contents(
                        $matches[1],
                        str_contains($matches[1], 'place-candidates')
                            ? implode("\n", $candidateLines)."\n"
                            : $rejectedCount."\n",
                    );
                }

                return new Process(['true']);
            },
        );
    }

    private function sqlContaining(string $needle): string
    {
        foreach ($this->captured as $command) {
            $sql = end($command);
            if (\is_string($sql) && str_contains($sql, $needle)) {
                return $sql;
            }
        }

        self::fail(\sprintf('no captured psql command contains "%s"', $needle));
    }

    private function hasSqlContaining(string $needle): bool
    {
        foreach ($this->captured as $command) {
            $sql = end($command);
            if (\is_string($sql) && str_contains($sql, $needle)) {
                return true;
            }
        }

        return false;
    }

    #[Test]
    public function theCacheLivesOutsideAnyPromotedSchema(): void
    {
        // Same reason as provisioner.wikidata_cache: a cache inside a schema that gets
        // promoted (or, before ADR-049, swapped) would be thrown away exactly when it is
        // most valuable, and ADR-049 §4 puts resolver_version here on purpose.
        $this->pass()->run($this->workDir, 'osm_staging_bretagne', 'accommodations');

        $ddl = $this->sqlContaining('CREATE TABLE IF NOT EXISTS provisioner.place_enrichment');
        self::assertStringContainsString('resolver_version integer NOT NULL', $ddl);
        self::assertStringContainsString('status text NOT NULL', $ddl);
        self::assertStringNotContainsString('osm_staging_bretagne.place_enrichment', $ddl);
    }

    #[Test]
    public function onlyScansRowsArrivingWithoutAName(): void
    {
        // A row already present and complete is neither read nor rewritten: the candidate
        // scan never looks at it.
        $this->pass()->run($this->workDir, 'osm_staging_bretagne', 'accommodations');

        $export = $this->sqlContaining('place-candidates.tsv');
        self::assertStringContainsString("WHERE nullif(btrim(a.name), '') IS NULL", $export);
        self::assertStringContainsString("a.category NOT IN ('shelter')", $export);
    }

    #[Test]
    public function skipsWhatThisResolverVersionHasAlreadyDecided(): void
    {
        // The acceptance criterion "a re-opening without a source change triggers no
        // enrichment network call": everything already decided is excluded from the export,
        // so the resolver sees nothing.
        $this->pass()->run($this->workDir, 'osm_staging_bretagne', 'accommodations');

        $export = $this->sqlContaining('place-candidates.tsv');
        self::assertStringContainsString(\sprintf("(c.status = 'resolved' OR c.resolver_version >= %d)", NameResolver::VERSION), $export);
    }

    #[Test]
    public function withNothingToResolveItWritesNothingToTheCache(): void
    {
        $this->pass()->run($this->workDir, 'osm_staging_bretagne', 'accommodations');

        self::assertFalse($this->hasSqlContaining('INSERT INTO provisioner.place_enrichment'), 'no cache write when the export is empty');
        self::assertFileDoesNotExist($this->workDir.'/place-resolved.copy');
    }

    #[Test]
    public function resolvesAnOperatorIntoANameAndCachesIt(): void
    {
        $counts = $this->pass([
            "N/1\tcamp_site\t{\"operator\": \"Commune de Jongieux\"}\t\tJongieux",
        ])->run($this->workDir, 'osm_staging_bretagne', 'accommodations');

        self::assertSame(1, $counts['resolved']);

        $copy = (string) file_get_contents($this->workDir.'/place-resolved.copy');
        self::assertStringContainsString('Commune de Jongieux', $copy);
        self::assertStringContainsString('resolved', $copy);

        $upsert = $this->sqlContaining('INSERT INTO provisioner.place_enrichment');
        self::assertStringContainsString(\sprintf('%d, now()', NameResolver::VERSION), $upsert);
        self::assertStringContainsString('ON CONFLICT (source, source_id) DO UPDATE', $upsert);
    }

    #[Test]
    public function cachesTheRejectionWithItsMotiveAndVersion(): void
    {
        // The negative cache: without it a re-opening would re-resolve every hopeless row,
        // and a later resolver could never tell which rows to reconsider.
        $counts = $this->pass([
            "N/2\tguest_house\t{\"building\": \"yes\"}\t\t",
        ])->run($this->workDir, 'osm_staging_bretagne', 'accommodations');

        self::assertSame(0, $counts['resolved']);
        self::assertSame(['no_usable_name_source' => 1], $counts['reasons']);

        $copy = (string) file_get_contents($this->workDir.'/place-resolved.copy');
        self::assertStringContainsString('insufficient', $copy);
        self::assertStringContainsString('no_usable_name_source', $copy);
    }

    #[Test]
    public function appliesResolvedNamesByCoalesceOnly(): void
    {
        // Completion, never rewriting (ADR-049 §4): the literal pattern of
        // WikidataEnrichmentPass:138. An existing value cannot be replaced.
        $this->pass()->run($this->workDir, 'osm_staging_bretagne', 'accommodations');

        $apply = $this->sqlContaining('UPDATE osm_staging_bretagne.accommodations a SET');
        self::assertStringContainsString("SET name = COALESCE(a.name, c.payload->>'name')", $apply);
        self::assertStringContainsString("c.status = 'resolved'", $apply);
        // Nothing else is assigned, so no payload column can be touched.
        self::assertSame(1, preg_match_all('/SET [a-z_]+ =/', $apply));
    }

    #[Test]
    public function theGateDeletesFromStagingAndNeverFromLive(): void
    {
        // Deleting from staging keeps the promotion's INSERT clean, so the live CHECK only
        // ever fires on a bug in this gate — which is exactly what it is for.
        $this->pass()->run($this->workDir, 'osm_staging_bretagne', 'accommodations');

        $gate = $this->sqlContaining('DELETE FROM osm_staging_bretagne.accommodations');
        self::assertStringContainsString("nullif(btrim(a.name), '') IS NULL", $gate);
        self::assertStringContainsString("a.category NOT IN ('shelter')", $gate);
        self::assertFalse($this->hasSqlContaining('DELETE FROM osm.accommodations'), 'the live table is never touched');
    }

    #[Test]
    public function countsWhatTheGateRefusedSoItCanBeReported(): void
    {
        // ADR-049 names this the model's blind spot: a CHECK that rejects is invisible
        // without the count, and the constraint alone would trade one blind spot for another.
        $counts = $this->pass(rejectedCount: '17')->run($this->workDir, 'osm_staging_bretagne', 'accommodations');

        self::assertSame(17, $counts['rejected']);
        self::assertStringContainsString('SELECT count(*) FROM osm_staging_bretagne.accommodations', $this->sqlContaining('place-rejected.tsv'));
    }

    #[Test]
    public function countsBeforeDeletingSoTheReportIsNotAlwaysZero(): void
    {
        $this->pass(rejectedCount: '3')->run($this->workDir, 'osm_staging_bretagne', 'accommodations');

        $count = $this->commandIndex('place-rejected.tsv');
        $delete = $this->commandIndex('DELETE FROM osm_staging_bretagne.accommodations');
        self::assertGreaterThan(-1, $count);
        self::assertLessThan($delete, $count);
    }

    #[Test]
    public function resolvesLocalityFromTheStagingBoundariesWhenNoSchemaIsGiven(): void
    {
        // The zone being opened has just imported its own commune boundaries; the live schema
        // does not have them until promotion.
        $this->pass()->run($this->workDir, 'osm_staging_bretagne', 'accommodations');

        $export = $this->sqlContaining('place-candidates.tsv');
        self::assertStringContainsString('FROM osm_staging_bretagne.admin_boundaries b WHERE b.admin_level = 8', $export);
        self::assertStringContainsString('ST_Covers(b.geom, a.geom)', $export);
    }

    #[Test]
    public function readsBoundariesFromTheGivenSchemaWhenOneIsSet(): void
    {
        // The DataTourisme side: the flux carries no administrative geometry, and the zone's
        // boundaries were promoted by the OSM step that always runs first.
        new PlaceEnrichmentPass(
            source: 'datatourisme',
            identity: 'a.id',
            exemptCategories: [],
            boundariesSchema: 'osm',
            processFactory: function (array $command): Process {
                /** @var list<string> $cmd */
                $cmd = $command;
                $this->captured[] = $cmd;

                return new Process(['true']);
            },
        )->run($this->workDir, 'tourism_staging_bretagne', 'accommodations');

        $export = $this->sqlContaining('place-candidates.tsv');
        self::assertStringContainsString('FROM osm.admin_boundaries b', $export);
        // No exemption on this side: every flux accommodation category is bookable.
        self::assertStringContainsString('AND true', $export);
        self::assertStringNotContainsString('NOT IN (', $export);
    }

    private function commandIndex(string $needle): int
    {
        foreach ($this->captured as $index => $command) {
            $sql = end($command);
            if (\is_string($sql) && str_contains($sql, $needle)) {
                return $index;
            }
        }

        return -1;
    }
}
