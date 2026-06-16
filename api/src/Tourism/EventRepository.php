<?php

declare(strict_types=1);

namespace App\Tourism;

use Doctrine\DBAL\Connection;

/**
 * Reads DataTourisme events from the local-first `tourism` schema (ADR-040),
 * replacing the runtime DataTourisme REST API. Events are dated, so the query
 * filters on the stage date as well as the spatial radius around its end point.
 */
final readonly class EventRepository implements EventRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @param string $date Y-m-d
     *
     * @return list<array{name: ?string, category: string, lat: float, lon: float, startDate: string, endDate: string, url: ?string, description: ?string, priceMin: ?float}>
     */
    public function findActiveNear(float $lat, float $lon, int $radiusMeters, string $date): array
    {
        /** @var list<array<string, scalar|null>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT name, category, url, description, price_min,
                       to_char(start_date, 'YYYY-MM-DD') AS start_date,
                       to_char(end_date, 'YYYY-MM-DD') AS end_date,
                       ST_Y(geom) AS lat, ST_X(geom) AS lon
                FROM tourism.events
                WHERE start_date <= :date AND end_date >= :date
                  AND ST_DWithin(
                      geom::geography,
                      ST_SetSRID(ST_MakePoint(:lon, :lat), 4326)::geography,
                      :radius
                  )
                ORDER BY start_date
                LIMIT 100
                SQL,
            [
                'date' => $date,
                'lon' => $lon,
                'lat' => $lat,
                'radius' => $radiusMeters,
            ],
        );

        $events = [];
        foreach ($rows as $row) {
            $events[] = [
                'name' => null !== $row['name'] ? (string) $row['name'] : null,
                'category' => (string) $row['category'],
                'lat' => (float) $row['lat'],
                'lon' => (float) $row['lon'],
                'startDate' => (string) $row['start_date'],
                'endDate' => (string) $row['end_date'],
                'url' => null !== $row['url'] && '' !== $row['url'] ? (string) $row['url'] : null,
                'description' => null !== $row['description'] && '' !== $row['description'] ? (string) $row['description'] : null,
                'priceMin' => null !== $row['price_min'] ? (float) $row['price_min'] : null,
            ];
        }

        return $events;
    }
}
