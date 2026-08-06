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
    private function pass(array $candidateLines = [], string $rejectedCount = '0', ?string $matchTable = null): PlaceEnrichmentPass
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
            matchTable: $matchTable,
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
            "N/1\tcamp_site\t{\"operator\": \"Commune de Jongieux\"}\t\tJongieux\t{}",
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
            "N/2\tguest_house\t{\"building\": \"yes\"}\t\t\t{}",
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

    #[Test]
    public function matchingIsOffUnlessACuratedTableIsGiven(): void
    {
        // DataTourisme may not be configured at all, and the OSM import must still run — with
        // one fewer resolver step, not with a broken query.
        $this->pass()->run($this->workDir, 'osm_staging_bretagne', 'accommodations');

        $export = $this->sqlContaining('place-candidates.tsv');
        self::assertStringNotContainsString('ST_DWithin', $export);
        // Six columns either way, the last a constant, so the reader's field count is stable.
        self::assertSame(1, preg_match("/LIMIT 1\), ''\),\s+'\{\}'/", $export), 'a constant stands in for the match');
    }

    #[Test]
    public function matchesOnCategoryAndProximityAlone(): void
    {
        // The loop #885 breaks: the runtime deduplicator pairs places *by name*, so it can
        // never complete a row whose name is missing. At import, category plus proximity is
        // enough — and the radius is tighter than the runtime's 75 m precisely because there
        // is no name to corroborate the match.
        $this->pass(matchTable: 'tourism_staging_bretagne.accommodations')
            ->run($this->workDir, 'osm_staging_bretagne', 'accommodations');

        $export = $this->sqlContaining('place-candidates.tsv');
        self::assertStringContainsString('FROM tourism_staging_bretagne.accommodations t', $export);
        self::assertStringContainsString('WHERE t.category = a.category', $export);
        self::assertStringContainsString(\sprintf('ST_DWithin(t.geom::geography, a.geom::geography, %d)', PlaceEnrichmentPass::DEFAULT_MATCH_RADIUS_METERS), $export);
        self::assertLessThan(75, PlaceEnrichmentPass::DEFAULT_MATCH_RADIUS_METERS, 'stricter than NearbyNameDeduplicator, which has equal names to corroborate it');
        // The count travels with the payload, which is what makes the ambiguity check possible
        // without a second query.
        self::assertStringContainsString("'n', count(*)", $export);
    }

    #[Test]
    public function inheritsTheNameAndWhatComesWithItFromASingleCuratedCandidate(): void
    {
        $counts = $this->pass([
            "N/1\tcamp_site\t{}\t\tSarlat\t".json_encode([
                'n' => 1,
                'id' => 'FR-123',
                'name' => 'Camping du Moulin',
                'description' => 'Au bord de la riviere',
                'website' => 'https://moulin.test',
                'opening_hours' => 'Apr-Oct',
                'distance_m' => 12.4,
            ], \JSON_THROW_ON_ERROR),
        ], matchTable: 'tourism_staging_bretagne.accommodations')
            ->run($this->workDir, 'osm_staging_bretagne', 'accommodations');

        self::assertSame(1, $counts['resolved']);
        self::assertSame(1, $counts['matched']);

        $copy = (string) file_get_contents($this->workDir.'/place-resolved.copy');
        self::assertStringContainsString('Camping du Moulin', $copy);
        self::assertStringContainsString('Au bord de la riviere', $copy);
        self::assertStringContainsString('https://moulin.test', $copy);
        // Traceable for audit: which record it came from, and how far away it was.
        self::assertStringContainsString('FR-123', $copy);
        self::assertStringContainsString('12.4', $copy);
        self::assertStringContainsString('datatourisme', $copy);
        // A curated record is already named the way the place presents itself, so the commune
        // is not appended on top of it.
        self::assertStringNotContainsString('Sarlat', $copy);
    }

    #[Test]
    public function refusesRatherThanChoosingBetweenTwoCandidates(): void
    {
        // Two neighbouring campsites, or a hotel and its restaurant at one address. Nothing
        // here can tell them apart, and a wrong name is worse than no name — the rider books
        // elsewhere, or turns up at the wrong place.
        $counts = $this->pass([
            "N/1\tcamp_site\t{}\t\t\t".json_encode([
                'n' => 2,
                'id' => 'FR-123',
                'name' => 'Camping du Moulin',
                'distance_m' => 9.0,
            ], \JSON_THROW_ON_ERROR),
        ], matchTable: 'tourism_staging_bretagne.accommodations')
            ->run($this->workDir, 'osm_staging_bretagne', 'accommodations');

        self::assertSame(0, $counts['resolved']);
        self::assertSame(1, $counts['ambiguous']);
        self::assertSame(['ambiguous_match' => 1], $counts['reasons']);

        $copy = (string) file_get_contents($this->workDir.'/place-resolved.copy');
        self::assertStringContainsString('ambiguous_match', $copy);
        // How many candidates made it ambiguous, for audit.
        self::assertStringContainsString('"candidates":2', $copy);
        self::assertStringNotContainsString('Camping du Moulin', $copy, 'no name is borrowed from an ambiguous match');
    }

    #[Test]
    public function fallsBackToTheTagsWhenNothingIsInRange(): void
    {
        // Out of range is not ambiguity: the cascade simply carries on to the next step.
        $counts = $this->pass([
            "N/1\tcamp_site\t{\"operator\": \"Commune de Jongieux\"}\t\t\t{}",
        ], matchTable: 'tourism_staging_bretagne.accommodations')
            ->run($this->workDir, 'osm_staging_bretagne', 'accommodations');

        self::assertSame(1, $counts['resolved']);
        self::assertSame(0, $counts['matched'], 'resolved by the operator tag, not by a match');
    }

    #[Test]
    public function carriesTheCuratedFieldsThroughTheSameCoalesceRule(): void
    {
        // An OSM value that exists always wins, description and site included: the match
        // completes a row, it never rewrites one.
        $this->pass(matchTable: 'tourism_staging_bretagne.accommodations')
            ->run($this->workDir, 'osm_staging_bretagne', 'accommodations');

        $apply = $this->sqlContaining('UPDATE osm_staging_bretagne.accommodations a SET');
        foreach (['name', 'description', 'website', 'opening_hours'] as $column) {
            self::assertStringContainsString($column.' = COALESCE(a.'.$column.", c.payload->>'".$column."')", $apply);
        }
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
