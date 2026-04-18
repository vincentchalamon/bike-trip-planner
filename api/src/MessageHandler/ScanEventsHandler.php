<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\Event;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\DataTourisme\DataTourismeClientInterface;
use App\Enum\ComputationName;
use App\Geo\GeoDistanceInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\ScanEvents;
use App\Repository\TripRequestRepositoryInterface;
use App\Wikidata\WikidataEnricherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ScanEventsHandler extends AbstractTripMessageHandler
{
    private const int EVENT_RADIUS_METERS = 20_000;

    private const float DEGREES_PER_METER = 1.0 / 111_320.0;

    /** @var list<string> */
    private const array TARGETED_TYPES = [
        'schema:Festival',
        'schema:Exhibition',
        'schema:MusicEvent',
        'urn:resource:FairOrShow',
    ];

    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        TripGenerationTrackerInterface $generationTracker,
        LoggerInterface $logger,
        private TripRequestRepositoryInterface $tripStateManager,
        private DataTourismeClientInterface $dataTourismeClient,
        private GeoDistanceInterface $haversine,
        private WikidataEnricherInterface $wikidataEnricher,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger);
    }

    public function __invoke(ScanEvents $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;

        if (!$this->dataTourismeClient->isEnabled()) {
            return;
        }

        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $request = $this->tripStateManager->getRequest($tripId);
        $startDate = $request?->startDate;

        if (!$startDate instanceof \DateTimeImmutable) {
            return;
        }

        $locale = $this->tripStateManager->getLocale($tripId) ?? 'en';

        $this->executeWithTracking($tripId, ComputationName::EVENTS, function () use ($tripId, $stages, $startDate, $locale, $generation): void {
            // Collect raw events per stage first, then enrich with Wikidata in one batch
            /** @var array<int, list<Event>> $eventsByStage */
            $eventsByStage = [];
            foreach ($stages as $i => $stage) {
                if ($stage->isRestDay) {
                    continue;
                }

                $stageDate = $startDate->modify(\sprintf('+%d days', $i));
                $eventsByStage[$i] = $this->fetchEventsForStage($stage, $stageDate);
            }

            // Wikidata enrichment: collect all Q-IDs and fetch in one batch
            $allEvents = [] !== $eventsByStage ? array_merge(...array_values($eventsByStage)) : [];
            $qIds = array_values(array_filter(array_unique(array_map(
                static fn (Event $e): ?string => $e->wikidataId,
                $allEvents,
            ))));
            $wikidataEnrichments = [] !== $qIds ? $this->wikidataEnricher->enrichBatch($qIds, $locale) : [];

            foreach ($eventsByStage as $i => $events) {
                $enrichedEvents = [];
                foreach ($events as $event) {
                    if (null !== $event->wikidataId && isset($wikidataEnrichments[$event->wikidataId])) {
                        $wikidata = $wikidataEnrichments[$event->wikidataId];
                        $event = new Event(
                            name: $event->name,
                            type: $event->type,
                            lat: $event->lat,
                            lon: $event->lon,
                            startDate: $event->startDate,
                            endDate: $event->endDate,
                            url: $event->url,
                            description: $event->description,
                            priceMin: $event->priceMin,
                            distanceToEndPoint: $event->distanceToEndPoint,
                            source: $event->source,
                            wikidataId: $event->wikidataId,
                            imageUrl: $event->imageUrl ?? $wikidata['imageUrl'] ?? null,
                            wikipediaUrl: $event->wikipediaUrl ?? $wikidata['wikipediaUrl'] ?? null,
                            openingHours: $event->openingHours ?? $wikidata['openingHours'] ?? null,
                        );
                    }

                    $enrichedEvents[] = $event;
                }

                $stage = $stages[$i];
                foreach ($enrichedEvents as $event) {
                    $stage->addEvent($event);
                }

                $payload = [
                    'stageIndex' => $i,
                    'events' => array_map(
                        static fn (Event $e): array => [
                            'name' => $e->name,
                            'type' => $e->type,
                            'lat' => $e->lat,
                            'lon' => $e->lon,
                            'startDate' => $e->startDate->format(\DateTimeInterface::ATOM),
                            'endDate' => $e->endDate->format(\DateTimeInterface::ATOM),
                            'url' => $e->url,
                            'description' => $e->description,
                            'priceMin' => $e->priceMin,
                            'distanceToEndPoint' => $e->distanceToEndPoint,
                            'source' => $e->source,
                            'wikidataId' => $e->wikidataId,
                            'imageUrl' => $e->imageUrl,
                            'wikipediaUrl' => $e->wikipediaUrl,
                            'openingHours' => $e->openingHours,
                        ],
                        $enrichedEvents,
                    ),
                ];

                $this->publisher->publish($tripId, MercureEventType::EVENTS_FOUND, $payload);

                $stages[$i] = $stage;
            }

            $this->tripStateManager->storeStages($tripId, array_values($stages));
        }, $generation);
    }

    /**
     * @return list<Event>
     */
    private function fetchEventsForStage(Stage $stage, \DateTimeImmutable $stageDate): array
    {
        $lat = $stage->endPoint->lat;
        $lon = $stage->endPoint->lon;

        $radiusDeg = self::EVENT_RADIUS_METERS * self::DEGREES_PER_METER;
        $minLat = $lat - $radiusDeg;
        $maxLat = $lat + $radiusDeg;
        $lonFactor = abs(cos(deg2rad($lat)));
        $lonDeg = 0.0 < $lonFactor ? $radiusDeg / $lonFactor : $radiusDeg;
        $minLon = $lon - $lonDeg;
        $maxLon = $lon + $lonDeg;

        $dateStr = $stageDate->format('Y-m-d');

        $response = $this->dataTourismeClient->request('/', [
            '@type' => 'schema:Event',
            'startDate[before]' => $dateStr,
            'endDate[after]' => $dateStr,
            'latitude[gte]' => $minLat,
            'latitude[lte]' => $maxLat,
            'longitude[gte]' => $minLon,
            'longitude[lte]' => $maxLon,
        ]);

        /** @var list<array<string, mixed>> $results */
        $results = \is_array($response['results'] ?? null) ? $response['results'] : [];

        $events = [];

        foreach ($results as $result) {
            $types = (array) ($result['@type'] ?? []);
            $matchedType = null;
            foreach (self::TARGETED_TYPES as $targeted) {
                if (\in_array($targeted, $types, true)) {
                    $matchedType = $targeted;
                    break;
                }
            }

            if (null === $matchedType) {
                continue;
            }

            $eventLat = $this->extractLat($result);
            $eventLon = $this->extractLon($result);

            if (null === $eventLat || null === $eventLon) {
                continue;
            }

            $startDate = $this->extractDate($result, 'startDate');
            $endDate = $this->extractDate($result, 'endDate');

            if (null === $startDate || null === $endDate) {
                continue;
            }

            $name = $this->extractLabel($result);

            if (null === $name) {
                continue;
            }

            $distanceToEndPoint = $this->haversine->inMeters(
                $eventLat,
                $eventLon,
                $stage->endPoint->lat,
                $stage->endPoint->lon,
            );

            $events[] = new Event(
                name: $name,
                type: $matchedType,
                lat: $eventLat,
                lon: $eventLon,
                startDate: $startDate,
                endDate: $endDate,
                url: $this->extractUrl($result),
                description: $this->extractDescription($result),
                priceMin: $this->extractPriceMin($result),
                distanceToEndPoint: $distanceToEndPoint,
                source: 'datatourisme',
                wikidataId: $this->extractWikidataId($result),
            );
        }

        usort($events, static fn (Event $a, Event $b): int => $a->startDate <=> $b->startDate);

        return $events;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function extractLat(array $result): ?float
    {
        $geometry = $result['hasGeometry'] ?? null;

        if (\is_array($geometry)) {
            $lat = $geometry['latitude'] ?? $geometry['lat'] ?? null;
            if (null !== $lat) {
                return (float) $lat;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function extractLon(array $result): ?float
    {
        $geometry = $result['hasGeometry'] ?? null;

        if (\is_array($geometry)) {
            $lon = $geometry['longitude'] ?? $geometry['lon'] ?? null;
            if (null !== $lon) {
                return (float) $lon;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function extractDate(array $result, string $field): ?\DateTimeImmutable
    {
        $value = $result[$field] ?? null;

        if (!\is_string($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function extractLabel(array $result): ?string
    {
        $label = $result['rdfs:label'] ?? null;

        if (\is_string($label)) {
            return $label;
        }

        if (\is_array($label)) {
            $first = array_values($label)[0] ?? null;

            return \is_string($first) ? $first : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function extractUrl(array $result): ?string
    {
        $url = $result['foaf:homepage'] ?? null;

        return \is_string($url) ? $url : null;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function extractDescription(array $result): ?string
    {
        $desc = $result['shortDescription'] ?? null;

        if (\is_string($desc)) {
            return $desc;
        }

        if (\is_array($desc)) {
            $first = array_values($desc)[0] ?? null;

            return \is_string($first) ? $first : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function extractPriceMin(array $result): ?float
    {
        $offers = $result['offers'] ?? null;

        if (!\is_array($offers)) {
            return null;
        }

        foreach ($offers as $offer) {
            if (!\is_array($offer)) {
                continue;
            }

            $priceSpec = $offer['priceSpecification'] ?? null;

            if (!\is_array($priceSpec)) {
                continue;
            }

            foreach ($priceSpec as $spec) {
                if (!\is_array($spec)) {
                    continue;
                }

                $price = $spec['minPrice'] ?? $spec['price'] ?? null;
                if (null !== $price) {
                    return (float) $price;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function extractWikidataId(array $result): ?string
    {
        $sameAs = $result['owl:sameAs'] ?? null;

        if (\is_string($sameAs)) {
            return $this->parseWikidataId($sameAs);
        }

        if (\is_array($sameAs)) {
            foreach ($sameAs as $uri) {
                if (!\is_string($uri)) {
                    continue;
                }

                $id = $this->parseWikidataId($uri);
                if (null !== $id) {
                    return $id;
                }
            }
        }

        return null;
    }

    private function parseWikidataId(string $uri): ?string
    {
        if (str_contains($uri, 'wikidata.org/entity/')) {
            $parts = explode('/', $uri);

            return end($parts) ?: null;
        }

        return null;
    }
}
