<?php

declare(strict_types=1);

namespace Provisioner;

use Provisioner\Exception\ImportFailedException;

/**
 * The staging half of the events pipeline both feeds share (DataTourisme, OpenAgenda):
 * the staging `events` table, its COPY rows, the bulk load and the upsert-and-purge
 * promotion ({@see EventsPromotion}, ADR-051 §4).
 *
 * The COPY rows are written by column name against {@see EventsPromotion::COLUMNS}, the
 * one column list the COPY file, the `\copy` and the promotion all read, so a positional
 * shift between them cannot happen.
 */
final readonly class EventsStaging
{
    /**
     * A subset of the live tourism.events the promotion completes with the `zone` /
     * `last_seen_at` provenance pair.
     */
    private const string DDL = 'id text NOT NULL PRIMARY KEY, name text, category text NOT NULL, start_date date, end_date date, url text, description text, price_min numeric(10, 2), source text NOT NULL DEFAULT %s, tags jsonb, geom geometry(Point, 4326) NOT NULL';

    private EventsPromotion $promotion;

    /**
     * @param string $source the feed, stamped on every row ('datatourisme' / 'openagenda');
     *                       the events table is multi-source (Version20260810120000)
     */
    public function __construct(
        private string $source,
        private ProcessRunner $processes,
    ) {
        $this->promotion = new EventsPromotion($this->source);
    }

    /**
     * Column definitions of the staging `events` table.
     */
    public function ddl(): string
    {
        return \sprintf(self::DDL, Sql::literal($this->source));
    }

    /**
     * @param array{id: string, name: string|null, category: string, start_date: string|null, end_date: string|null, url: string|null, description: string|null, price_min: float|null, tags: array<string, mixed>, lat: float, lon: float} $event
     */
    public function line(array $event): string
    {
        $values = [
            'id' => $event['id'],
            'name' => $event['name'],
            'category' => $event['category'],
            'start_date' => $event['start_date'],
            'end_date' => $event['end_date'],
            'url' => $event['url'],
            'description' => $event['description'],
            'price_min' => $event['price_min'],
            'source' => $this->source,
            'tags' => CopyWriter::json($event['tags']),
            'geom' => CopyWriter::point($event['lat'], $event['lon']),
        ];

        return CopyWriter::line(array_map(static fn (string $column): string|float|null => $values[$column], EventsPromotion::COLUMNS));
    }

    /**
     * Loads a COPY file into a fresh schema holding the `events` table alone.
     *
     * @throws ImportFailedException
     */
    public function load(string $schema, string $copyFile): void
    {
        $this->processes->psql(
            \sprintf('DROP SCHEMA IF EXISTS %1$s CASCADE; CREATE SCHEMA %1$s; CREATE TABLE %1$s.events (%2$s);', $schema, $this->ddl()),
            'psql create events staging',
        );
        $this->copy($schema, $copyFile);
        $this->index($schema);
    }

    /**
     * @throws ImportFailedException
     */
    public function copy(string $schema, string $copyFile): void
    {
        $this->processes->psql(
            \sprintf("\\copy %s.events (%s) FROM '%s'", $schema, implode(', ', EventsPromotion::COLUMNS), $copyFile),
            'psql copy events',
        );
    }

    /**
     * Serves the zone clip: the promotion tests every staged row against the zone polygon
     * with ST_Covers.
     *
     * @throws ImportFailedException
     */
    public function index(string $schema): void
    {
        $this->processes->psql(\sprintf('CREATE INDEX ON %s.events USING gist (geom);', $schema), 'psql index events');
    }

    /**
     * Upserts the staged events covered by the zone and purges past events, in one
     * transaction.
     *
     * @param string $today the purge boundary as `YYYY-MM-DD` (see {@see EventsPromotion})
     *
     * @throws ImportFailedException
     */
    public function promote(string $zoneSlug, string $schema, string $today): void
    {
        $this->processes->psql(PromotionReportTable::ddl(), 'psql prepare events promotion report');
        $this->processes->psql(
            $this->promotion->sql($zoneSlug, $schema, $today),
            \sprintf('psql upsert+purge %s events zone %s', $this->source, $zoneSlug),
            singleTransaction: true,
        );
    }
}
