<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Provisioner\ZonePromotion;

final class ZonePromotionTest extends TestCase
{
    private function promotion(): ZonePromotion
    {
        return new ZonePromotion('osm', 'osm', [
            'pois' => 'l.osm_type = s.osm_type AND l.osm_id = s.osm_id',
            'ways' => 'l.osm_id = s.osm_id',
        ]);
    }

    #[Test]
    public function neverDropsOrRenamesTheLiveSchema(): void
    {
        // The whole point of ADR-049 §2: the destructive step is gone. A staging-schema
        // name out of step with the flex style used to rename an empty schema over the
        // live one; there is no rename left to get wrong.
        $sql = $this->promotion()->sql('bretagne', 'osm_staging_bretagne');

        self::assertStringNotContainsString('DROP SCHEMA', $sql);
        self::assertStringNotContainsString('RENAME TO', $sql);
        self::assertStringNotContainsString('TRUNCATE', $sql);
    }

    #[Test]
    public function insertsOnlyKeysTheLiveTablesDoNotHold(): void
    {
        $sql = $this->promotion()->sql('bretagne', 'osm_staging_bretagne');

        self::assertStringContainsString('INSERT INTO %1$I.%2$I (%3$s, zone, last_seen_at)', $sql);
        self::assertStringContainsString('WHERE NOT EXISTS (SELECT 1 FROM %1$I.%2$I l WHERE %6$s)', $sql);
        // Per-table identity, because ways/admin_boundaries/cycle_routes carry a single
        // object type and therefore have no osm_type column.
        self::assertStringContainsString("('pois', 'l.osm_type = s.osm_type AND l.osm_id = s.osm_id')", $sql);
        self::assertStringContainsString("('ways', 'l.osm_id = s.osm_id')", $sql);
    }

    #[Test]
    public function neverRewritesAnImportedPayload(): void
    {
        // Append-only with obsolescence assumed (ADR-049 §5): the only UPDATE in the
        // whole promotion sets last_seen_at, which is metadata. Anything else would be a
        // rewrite the model forbids.
        $sql = $this->promotion()->sql('bretagne', 'osm_staging_bretagne');

        preg_match_all('/SET ([a-z_]+) =/', $sql, $matches);
        self::assertSame(
            // last_seen_at is the metadata touch; `candidates` opens the report's own
            // ON CONFLICT assignment list. No payload column is ever assigned.
            ['last_seen_at', 'candidates'],
            $matches[1],
            'no promoted payload column is ever assigned',
        );
    }

    #[Test]
    public function refreshesLastSeenAtBeforeInserting(): void
    {
        // Order matters: touching after the insert would stamp the rows this run added,
        // making "seen again" indistinguishable from "just arrived".
        $sql = $this->promotion()->sql('bretagne', 'osm_staging_bretagne');

        $touch = strpos($sql, 'SET last_seen_at = now()');
        $insert = strpos($sql, 'INSERT INTO %1$I.%2$I (%3$s, zone, last_seen_at)');
        self::assertIsInt($touch);
        self::assertIsInt($insert);
        self::assertLessThan($insert, $touch);
    }

    #[Test]
    public function readsTheColumnListFromInformationSchemaRatherThanHardcodingIt(): void
    {
        // tier1.lua stays the single source of truth for what a feature table holds.
        $sql = $this->promotion()->sql('bretagne', 'osm_staging_bretagne');

        self::assertStringContainsString('FROM information_schema.columns', $sql);
        self::assertStringContainsString("string_agg(quote_ident(c.column_name), ', ' ORDER BY c.ordinal_position)", $sql);
    }

    #[Test]
    public function abortsWhenAStagingColumnHasNoLiveCounterpart(): void
    {
        // Promotion by INSERT makes the migrated DDL authoritative, so a column added to
        // the flex style and not to a migration must fail loudly. The swap used to hide
        // exactly this by replacing the whole schema.
        $sql = $this->promotion()->sql('bretagne', 'osm_staging_bretagne');

        self::assertStringContainsString('RAISE EXCEPTION', $sql);
        self::assertStringContainsString('Add them in an api/migrations migration', $sql);
        self::assertStringContainsString('does not exist.', $sql);
    }

    #[Test]
    public function stampsEveryPromotedRowWithItsZone(): void
    {
        $sql = $this->promotion()->sql('bretagne', 'osm_staging_bretagne');

        self::assertStringContainsString("'bretagne'", $sql);
        self::assertStringContainsString('SELECT %3$s, %4$L, now() FROM %5$I.%2$I s', $sql);
    }

    #[Test]
    public function clipsToTheZoneGeometryWhenAsked(): void
    {
        // The DataTourisme flux is national: without the clip, opening Brittany would
        // import the whole country's places under Brittany's name.
        $clipped = new ZonePromotion('datatourisme', 'tourism', ['events' => 'l.id = s.id'])
            ->sql('bretagne', 'tourism_staging_bretagne', clipToZone: 'bretagne');

        self::assertStringContainsString("ST_Covers((SELECT geom FROM osm.zones WHERE slug = ''bretagne''), s.geom)", $clipped);
        // Doubled quotes: the clip is spliced into single-quoted format() templates, so
        // a single quote there would end the template and produce invalid SQL.
        self::assertStringNotContainsString("slug = 'bretagne'), s.geom)", $clipped);

        // The clip applies to the count and the touch too, not just the insert: a run
        // opening one zone must leave rows outside it untouched, metadata included.
        self::assertSame(3, substr_count($clipped, 'ST_Covers'));
    }

    #[Test]
    public function isNotClippedByDefault(): void
    {
        // The OSM extract is already the zone, so clipping it would only cost a scan —
        // and would drop the rows a clipped Geofabrik extract holds just outside the
        // administrative boundary it failed to build (#880).
        $sql = $this->promotion()->sql('bretagne', 'osm_staging_bretagne');

        self::assertStringNotContainsString('ST_Covers', $sql);
    }

    #[Test]
    public function recordsCandidateAndInsertedCountsPerTable(): void
    {
        $sql = $this->promotion()->sql('bretagne', 'osm_staging_bretagne');

        self::assertStringContainsString('GET DIAGNOSTICS added = ROW_COUNT', $sql);
        self::assertStringContainsString('INSERT INTO provisioner.promotion_report', $sql);
        self::assertStringContainsString('ON CONFLICT (source, zone, table_name) DO UPDATE', $sql);
        self::assertStringContainsString("'osm'", $sql);
    }

    #[Test]
    public function splicesTheRegistryUpsertInsideTheSameTransaction(): void
    {
        $sql = $this->promotion()->sql(
            'bretagne',
            'osm_staging_bretagne',
            registryUpsert: 'INSERT INTO osm.zones (slug) SELECT '."'bretagne'".';',
        );

        $registry = strpos($sql, 'INSERT INTO osm.zones');
        $end = strpos($sql, '$promote$;');
        self::assertIsInt($registry);
        self::assertIsInt($end);
        self::assertLessThan($end, $registry, 'the registry write is part of the promotion block, not a second statement');
    }

    #[Test]
    public function reportDdlIsIdempotent(): void
    {
        $ddl = $this->promotion()->reportDdl();

        self::assertStringContainsString('CREATE SCHEMA IF NOT EXISTS provisioner', $ddl);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS provisioner.promotion_report', $ddl);
    }

    #[Test]
    public function escapesQuotesInEveryInterpolatedValue(): void
    {
        $sql = new ZonePromotion("o'source", "o'live", ["o'table" => 'l.id = s.id'])
            ->sql("o'zone", "o'staging", clipToZone: "o'clip");

        self::assertStringNotContainsString("'o'zone'", $sql);
        self::assertStringContainsString("'o''zone'", $sql);
        self::assertStringContainsString("'o''live'", $sql);
        self::assertStringContainsString("'o''table'", $sql);
    }
}
