<?php

declare(strict_types=1);

namespace App\EventSource;

use App\Tourism\EventRepositoryInterface;

/**
 * DataTourisme contribution to the events scan: the tourism.events layer read
 * from the local-first PostGIS schema (ADR-040). The repository already filters
 * out linkless events, orders by distance and stamps the `source` column, so this
 * only adapts it to the tagged-iterator {@see EventSourceRegistry}. A second
 * source (OpenAgenda, #984) joins by implementing the same interface.
 */
final readonly class DataTourismeEventSource implements EventSourceInterface
{
    public function __construct(private EventRepositoryInterface $eventRepository)
    {
    }

    public function findActiveNear(float $lat, float $lon, int $radiusMeters, string $date): array
    {
        return $this->eventRepository->findActiveNear($lat, $lon, $radiusMeters, $date);
    }
}
