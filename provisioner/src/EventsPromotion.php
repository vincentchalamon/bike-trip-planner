<?php

declare(strict_types=1);

namespace Provisioner;

/**
 * Builds the events promotion SQL: an **upsert** into the live events table plus a
 * temporal **purge**, run together in one transaction (ADR-051 §4).
 *
 * Events are the one reference layer that is *not* append-only. A place is there next
 * year; an event that ended last week is dead weight (ADR-040 / ADR-049 assume
 * obsolescence but never expiry). So where {@see ZonePromotion} inserts only the keys the
 * live table does not already hold and never rewrites a payload, this:
 *
 * - **upserts** — `ON CONFLICT (id) DO UPDATE` refreshes the mutable fields (name, dates,
 *   url, description, price, category, tags, geom, source, last_seen_at). An event whose
 *   dates moved is updated in place, not duplicated. `zone` is deliberately *not* in the
 *   set: it records which zone first imported the row and is provenance, not payload.
 * - **purges** — `DELETE ... WHERE end_date < :today`, in the same transaction and *after*
 *   the upsert (a separate statement, so it also drops a just-upserted row that has
 *   already ended). `:today` is a literal date computed by the caller with an explicit
 *   timezone; the SQL never says `now()` / `CURRENT_DATE`, whose day boundary drifts with
 *   the server timezone (Europe/Paris in CI, UTC on a throwaway container).
 *
 * The upsert is clipped to the zone's registry geometry, exactly as {@see ZonePromotion}
 * clips a national flux to one zone. The purge is global on purpose: a passed event is
 * dead weight everywhere, not only in the zone being refreshed.
 *
 * Per-run counts are written to {@see ZonePromotion::REPORT_TABLE} (candidates = staged
 * rows in the zone, inserted = genuinely new rows, told apart from updates by the `xmax`
 * system column) so a refresh reports what it did, like every other promotion.
 */
final readonly class EventsPromotion
{
    /**
     * Mutable fields refreshed on conflict. `id` is the key, `zone` and `last_seen_at` are
     * handled apart (zone is never rewritten, last_seen_at is always touched).
     *
     * @var list<string>
     */
    private const array MUTABLE = ['name', 'category', 'start_date', 'end_date', 'url', 'description', 'price_min', 'source', 'tags', 'geom'];

    /**
     * Insert/select column order, matching the staging `events` table both importers load.
     *
     * @var list<string>
     */
    private const array COLUMNS = ['id', 'name', 'category', 'start_date', 'end_date', 'url', 'description', 'price_min', 'source', 'tags', 'geom'];

    /**
     * @param string $source     provenance stamped in the promotion report ('datatourisme' / 'openagenda')
     * @param string $liveSchema schema holding the live `events` table; the `tourism` default is
     *                           overridden only by the execution test's scratch schema
     * @param string $zonesTable qualified table carrying `slug` / `geom` to clip against;
     *                           `osm.zones` in production, a scratch table under test
     */
    public function __construct(
        private string $source,
        private string $liveSchema = 'tourism',
        private string $zonesTable = 'osm.zones',
    ) {
    }

    /**
     * DDL for the shared promotion report table; safe to run on every pass.
     */
    public function reportDdl(): string
    {
        return \sprintf(
            'CREATE SCHEMA IF NOT EXISTS provisioner; CREATE TABLE IF NOT EXISTS %s (source text NOT NULL, zone text NOT NULL, table_name text NOT NULL, candidates bigint NOT NULL, inserted bigint NOT NULL, promoted_at timestamptz NOT NULL, PRIMARY KEY (source, zone, table_name));',
            ZonePromotion::REPORT_TABLE,
        );
    }

    /**
     * Upsert + purge, two statements to run together under `psql --single-transaction`.
     *
     * @param string $today the purge boundary as `YYYY-MM-DD`, computed by the caller with
     *                      an explicit timezone (never derived in SQL)
     *
     * @throws \InvalidArgumentException when $today is not an ISO date, so a malformed value
     *                                   cannot reach the spliced literal
     */
    public function sql(string $zone, string $stagingSchema, string $today): string
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) {
            throw new \InvalidArgumentException(\sprintf('The purge date must be YYYY-MM-DD, got "%s".', $today));
        }

        $cols = implode(', ', self::COLUMNS);
        $set = implode(",\n            ", array_map(
            static fn (string $column): string => \sprintf('%1$s = EXCLUDED.%1$s', $column),
            self::MUTABLE,
        ));
        $liveTable = $this->liveSchema.'.events';

        return strtr(<<<'SQL'
            WITH upserted AS (
                INSERT INTO :live (:cols, zone, last_seen_at)
                SELECT :cols, :zone, now()
                  FROM :staging.events s
                 WHERE ST_Covers((SELECT geom FROM :zones WHERE slug = :zone), s.geom)
                ON CONFLICT (id) DO UPDATE SET
                    :set,
                    last_seen_at = EXCLUDED.last_seen_at
                RETURNING (xmax = 0) AS inserted
            )
            INSERT INTO :report (source, zone, table_name, candidates, inserted, promoted_at)
            SELECT :source, :zone, 'events', count(*), count(*) FILTER (WHERE inserted), now()
              FROM upserted
            ON CONFLICT (source, zone, table_name) DO UPDATE
               SET candidates = excluded.candidates, inserted = excluded.inserted, promoted_at = excluded.promoted_at;
            DELETE FROM :live WHERE end_date < :today;
            SQL, [
            ':live' => $liveTable,
            ':cols' => $cols,
            ':set' => $set,
            ':staging' => $stagingSchema,
            ':zones' => $this->zonesTable,
            ':zone' => ZonePromotion::literal($zone),
            ':report' => ZonePromotion::REPORT_TABLE,
            ':source' => ZonePromotion::literal($this->source),
            ':today' => ZonePromotion::literal($today).'::date',
        ]);
    }
}
