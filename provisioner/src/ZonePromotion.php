<?php

declare(strict_types=1);

namespace Provisioner;

/**
 * Builds the per-zone promotion SQL that replaces the global schema swap (ADR-049 §2,
 * issue #883).
 *
 * The old model was `DROP SCHEMA osm CASCADE; ALTER SCHEMA osm_staging RENAME TO osm`:
 * every zone exposed to every import, and a staging-schema name out of step with
 * osm2pgsql renaming an empty schema over the live one — destroying the data. Promotion
 * is now an `INSERT ... SELECT` restricted to keys the live tables do not already hold,
 * run inside one transaction. The worst outcome of a bug is 0 rows or a raised
 * exception; no other zone is ever touched.
 *
 * Three properties the generated SQL carries deliberately:
 *
 * - **Identity anti-join only.** The primary keys already exist — `(osm_type, osm_id)`
 *   for the OSM tables, `id` for the DataTourisme ones — so "already imported" is a key
 *   lookup, not a payload comparison. Re-opening an unchanged zone inserts nothing and
 *   rewrites nothing (ADR-049 §4/§5). Completing a NULL field is the enrichment pass's
 *   job, by COALESCE, not this one's.
 * - **`last_seen_at` is the single exception to "never written again"**, and it is
 *   metadata. It is refreshed *before* the insert so it cannot match the rows this run
 *   adds, which keeps "seen again" distinguishable from "just arrived".
 * - **The column list is read from `information_schema`, never hardcoded.** tier1.lua
 *   stays the single source of truth for what a feature table holds. A staging column
 *   with no live counterpart aborts the promotion with an actionable message instead of
 *   being silently dropped — the failure the swap used to hide by replacing the whole
 *   schema.
 */
final readonly class ZonePromotion
{
    /**
     * Bumped when the import pipeline changes in a way that makes already-imported rows
     * worth re-deriving; recorded per zone in the registry so an operator can tell which
     * zones predate the change. Not a schema version: the DDL is versioned by the
     * Doctrine migrations.
     */
    public const int PIPELINE_VERSION = 1;

    /**
     * Per-run, per-source promotion detail: how many rows the staging schema offered and
     * how many were new. Lives in the stable `provisioner` schema (the one the Wikidata
     * cache already uses) rather than in a swapped schema, and is created by the
     * provisioner itself so a database whose API migrations have not run still
     * provisions.
     */
    public const string REPORT_TABLE = 'provisioner.promotion_report';

    /**
     * @param array<string, string> $tables table name => predicate joining a live row `l` to a
     *                                      staging row `s`. The OSM tables holding a single object
     *                                      type (`ways`, `admin_boundaries`, `cycle_routes`) have no
     *                                      `osm_type` column, so their key is the id alone — which is
     *                                      why this cannot be one predicate for all of them.
     */
    public function __construct(
        private string $source,
        private string $liveSchema,
        private array $tables,
    ) {
    }

    /**
     * DDL for the report table; safe to run on every pass.
     */
    public function reportDdl(): string
    {
        return \sprintf(
            'CREATE SCHEMA IF NOT EXISTS provisioner; CREATE TABLE IF NOT EXISTS %s (source text NOT NULL, zone text NOT NULL, table_name text NOT NULL, candidates bigint NOT NULL, inserted bigint NOT NULL, promoted_at timestamptz NOT NULL, PRIMARY KEY (source, zone, table_name));',
            self::REPORT_TABLE,
        );
    }

    /**
     * The promotion itself, one statement to run under `psql --single-transaction`.
     *
     * @param string      $clipToZone     when set, only staging rows covered by that zone's registry
     *                                    geometry are promoted. The DataTourisme flux is national, so
     *                                    without the clip opening one zone would import a whole
     *                                    country's places under that zone's name.
     * @param string|null $registryUpsert extra SQL run inside the same transaction and after the
     *                                    loop, with the accumulated `candidates` and `counts` jsonb
     *                                    in scope; the OSM side uses it to write osm.zones. null for
     *                                    a source that does not own the registry.
     */
    public function sql(string $zone, string $stagingSchema, string $clipToZone = '', ?string $registryUpsert = null): string
    {
        $specs = implode(', ', array_map(
            static fn (string $table, string $identity): string => \sprintf('(%s, %s)', self::literal($table), self::literal($identity)),
            array_keys($this->tables),
            array_values($this->tables),
        ));

        // Appended to the count, the last_seen_at touch and the insert alike: a run
        // opening one zone must leave every row outside it strictly untouched, metadata
        // included. It is spliced into the single-quoted format() templates below, hence
        // the doubled quotes.
        $clip = '' === $clipToZone
            ? ''
            : $this->embedded(\sprintf(
                ' AND ST_Covers((SELECT geom FROM osm.zones WHERE slug = %s), s.geom)',
                self::literal($clipToZone),
            ));

        return strtr(<<<'SQL'
            DO $promote$
            DECLARE
                spec record;
                cols text;
                extra text;
                offered bigint;
                added bigint;
                candidates jsonb := '{}'::jsonb;
                counts jsonb := '{}'::jsonb;
            BEGIN
                FOR spec IN SELECT * FROM (VALUES :specs) AS t(tbl, idmatch) LOOP
                    SELECT string_agg(quote_ident(c.column_name), ', ' ORDER BY c.ordinal_position)
                      INTO extra
                      FROM information_schema.columns c
                     WHERE c.table_schema = :staging AND c.table_name = spec.tbl
                       AND NOT EXISTS (
                             SELECT 1 FROM information_schema.columns v
                              WHERE v.table_schema = :live AND v.table_name = spec.tbl
                                AND v.column_name = c.column_name);

                    IF extra IS NOT NULL THEN
                        RAISE EXCEPTION 'zone promotion aborted: staging table %.% carries column(s) % that %.% does not have. Add them in an api/migrations migration, then provision again.',
                            :staging, spec.tbl, extra, :live, spec.tbl;
                    END IF;

                    SELECT string_agg(quote_ident(c.column_name), ', ' ORDER BY c.ordinal_position)
                      INTO cols
                      FROM information_schema.columns c
                     WHERE c.table_schema = :staging AND c.table_name = spec.tbl;

                    IF cols IS NULL THEN
                        RAISE EXCEPTION 'zone promotion aborted: staging table %.% does not exist.', :staging, spec.tbl;
                    END IF;

                    EXECUTE format('SELECT count(*) FROM %1$I.%2$I s WHERE true:clip', :staging, spec.tbl) INTO offered;

                    EXECUTE format(
                        'UPDATE %1$I.%2$I l SET last_seen_at = now() FROM %3$I.%2$I s WHERE %4$s:clip',
                        :live, spec.tbl, :staging, spec.idmatch);

                    EXECUTE format(
                        'INSERT INTO %1$I.%2$I (%3$s, zone, last_seen_at) SELECT %3$s, %4$L, now() FROM %5$I.%2$I s WHERE NOT EXISTS (SELECT 1 FROM %1$I.%2$I l WHERE %6$s):clip',
                        :live, spec.tbl, cols, :zone, :staging, spec.idmatch);
                    GET DIAGNOSTICS added = ROW_COUNT;

                    candidates := candidates || jsonb_build_object(spec.tbl, offered);
                    counts := counts || jsonb_build_object(spec.tbl, added);

                    INSERT INTO :report (source, zone, table_name, candidates, inserted, promoted_at)
                    VALUES (:source, :zone, spec.tbl, offered, added, now())
                    ON CONFLICT (source, zone, table_name) DO UPDATE
                       SET candidates = excluded.candidates,
                           inserted = excluded.inserted,
                           promoted_at = excluded.promoted_at;
                END LOOP;
            :registry
            END
            $promote$;
            SQL, [
            ':specs' => $specs,
            ':staging' => self::literal($stagingSchema),
            ':live' => self::literal($this->liveSchema),
            ':zone' => self::literal($zone),
            ':source' => self::literal($this->source),
            ':report' => self::REPORT_TABLE,
            ':clip' => $clip,
            ':registry' => $registryUpsert ?? '',
        ]);
    }

    /**
     * Single-quoted SQL literal. Every value reaching this today is an internal constant
     * or a slug already resolved against {@see GeofabrikRegionRegistry}, so this guards
     * the boundary rather than sanitising untrusted input.
     *
     * Public because it is the single place that escaping lives: {@see PostgisImporter}
     * builds the registry upsert spliced into this SQL and must quote the zone's name
     * the same way. Two copies would let a future change to the quoting strategy be
     * applied to one and forgotten in the other.
     */
    public static function literal(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * Escapes a SQL fragment for splicing into a single-quoted `format()` template.
     */
    private function embedded(string $fragment): string
    {
        return str_replace("'", "''", $fragment);
    }
}
