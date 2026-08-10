<?php

declare(strict_types=1);

namespace App\EventSource;

use App\Geo\GeoDistanceInterface;
use App\Geo\NearbyNameDeduplicator;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Reads every event source active on a stage date and returns a relevant,
 * deduplicated, distance-ranked and capped list for the stage end point (ADR-051).
 *
 * The cross-source pass mirrors {@see \App\Poi\PoiSourceRegistry}: merge every
 * source, collapse the same event reported twice (name + proximity via
 * {@see NearbyNameDeduplicator}, the curated DataTourisme entry winning on a tie),
 * then apply the rules that must hold identically whatever the origin:
 *
 * - **Relevance.** Only the event categories a bikepacker plans a detour around are
 *   kept ({@see RELEVANT_CATEGORIES}); the generic `event` fallback and any
 *   young-audience / children category a source normalises outside this set are
 *   dropped. Categories are pre-normalised to the app vocabulary by each source,
 *   so this single whitelist is the shared audience filter across sources.
 * - **A link is mandatory.** Enforced in SQL too (EventRepository), re-checked here
 *   so a source that forgets cannot leak a linkless event into the stage.
 * - **Ranked by distance to the end point, capped.** A rider cares about what is
 *   near where the day ends, not about the earliest start date, and a stage shows a
 *   handful of events, not a hundred.
 */
readonly class EventSourceRegistry
{
    /**
     * App-normalised event categories worth surfacing. Matches the DataTourisme
     * mapper's EVENT_CATEGORY targets; a new source maps its own taxonomy onto the
     * same vocabulary.
     */
    public const array RELEVANT_CATEGORIES = ['festival', 'concert', 'exhibition', 'sports', 'fair', 'show'];

    private const int CAP = 20;

    /** @var list<EventSourceInterface> */
    private array $sources;

    /**
     * @param iterable<EventSourceInterface> $sources
     */
    public function __construct(
        #[AutowireIterator('app.event_source')]
        iterable $sources,
        private NearbyNameDeduplicator $deduplicator,
        private GeoDistanceInterface $haversine,
    ) {
        $this->sources = iterator_to_array($sources, false);
    }

    /**
     * @param string $date Y-m-d
     *
     * @return list<array{name: ?string, category: string, lat: float, lon: float, startDate: string, endDate: string, url: string, description: ?string, priceMin: ?float, source: string, distanceToEndPoint: float}>
     */
    public function findAllActiveNear(float $lat, float $lon, int $radiusMeters, string $date): array
    {
        $all = [];
        foreach ($this->sources as $source) {
            foreach ($source->findActiveNear($lat, $lon, $radiusMeters, $date) as $event) {
                if ('' === $event['url'] || !\in_array($event['category'], self::RELEVANT_CATEGORIES, true)) {
                    continue;
                }

                $all[] = $event;
            }
        }

        /** @var list<array{name: ?string, category: string, lat: float, lon: float, startDate: string, endDate: string, url: string, description: ?string, priceMin: ?float, source: string}> $deduped */
        $deduped = $this->deduplicator->dedupe($all);

        $ranked = array_map(
            fn (array $event): array => $event + [
                'distanceToEndPoint' => $this->haversine->inMeters($event['lat'], $event['lon'], $lat, $lon),
            ],
            $deduped,
        );

        usort($ranked, static fn (array $a, array $b): int => $a['distanceToEndPoint'] <=> $b['distanceToEndPoint']);

        return \array_slice($ranked, 0, self::CAP);
    }
}
