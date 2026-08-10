<?php

declare(strict_types=1);

namespace App\EventSource;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.event_source')]
interface EventSourceInterface
{
    /**
     * Dated events active on $date (start_date <= $date <= end_date) within
     * $radiusMeters of the point, each carrying its origin `source`.
     *
     * Only events with a usable link are returned: an event a rider cannot open
     * is noise. `name` may be null (the row shape is shared with the deduplicator,
     * which never merges an anonymous entry); the `category` is the app-normalised
     * event vocabulary, the same across every source so relevance can be decided
     * once in {@see EventSourceRegistry}.
     *
     * @param string $date Y-m-d
     *
     * @return list<array{name: ?string, category: string, lat: float, lon: float, startDate: string, endDate: string, url: string, description: ?string, priceMin: ?float, source: string}>
     */
    public function findActiveNear(float $lat, float $lon, int $radiusMeters, string $date): array;
}
