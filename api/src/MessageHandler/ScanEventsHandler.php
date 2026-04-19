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
use App\Repository\MarketRepositoryInterface;
use App\Repository\TripRequestRepositoryInterface;
use App\Wikidata\WikidataEnricherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private MarketRepositoryInterface $marketRepository,
        private TranslatorInterface $translator,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger);
    }

    public function __invoke(ScanEvents $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;

        $stages = $this->tripStateManager->getStages($tripId);
        if (null === $stages) {
            $this->executeWithTracking($tripId, ComputationName::EVENTS, static fn (): null => null, $generation);

            return;
        }

        $request = $this->tripStateManager->getRequest($tripId);
        $startDate = $request?->startDate;
        if (!$startDate instanceof \DateTimeImmutable) {
            $this->executeWithTracking($tripId, ComputationName::EVENTS, static fn (): null => null, $generation);

            return;
        }

        $locale = $this->tripStateManager->getLocale($tripId) ?? 'en';

        if (!$this->dataTourismeClient->isEnabled()) {
            $this->executeWithTracking($tripId, ComputationName::EVENTS, function () use ($tripId, $stages, $startDate, $locale): void {
                foreach ($stages as $i => $stage) {
                    if ($stage->isRestDay) {
                        continue;
                    }

                    $stageDate = $startDate->modify(\sprintf('+%d days', $i));
                    $events = $this->fetchMarketsForStage($stage, $stageDate, $locale);

                    foreach ($events as $event) {
                        $stage->addEvent($event);
                    }

                    if ([] !== $events) {
                        $this->publisher->publish($tripId, MercureEventType::EVENTS_FOUND, [
                            'stageIndex' => $i,
                            'events' => array_map($this->eventToArray(...), $events),
                        ]);
                    }

                    $stages[$i] = $stage;
                }

                $this->tripStateManager->storeStages($tripId, array_values($stages));
            }, $generation);

            return;
        }

        $this->executeWithTracking($tripId, ComputationName::EVENTS, function () use ($tripId, $stages, $startDate, $locale): void {
            // Collect raw events per stage first, then enrich with Wikidata in one batch
            /** @var array<int, list<Event>> $eventsByStage */
            $eventsByStage = [];
            foreach ($stages as $i => $stage) {
                if ($stage->isRestDay) {
                    continue;
                }

                $stageDate = $startDate->modify(\sprintf('+%d days', $i));
                $events = $this->fetchEventsForStage($stage, $stageDate);
                $events = [...$events, ...$this->fetchMarketsForStage($stage, $stageDate, $locale)];
                $eventsByStage[$i] = $events;
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

                $this->publisher->publish($tripId, MercureEventType::EVENTS_FOUND, [
                    'stageIndex' => $i,
                    'events' => array_map($this->eventToArray(...), $enrichedEvents),
                ]);

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

        $response = $this->dataTourismeClient->request('/api/v1/events', [
            'filters[0][path]' => '@type',
            'filters[0][operator]' => 'in',
            'filters[0][value]' => implode(',', self::TARGETED_TYPES),
            'filters[1][path]' => 'startDate',
            'filters[1][operator]' => 'lte',
            'filters[1][value]' => $dateStr,
            'filters[2][path]' => 'endDate',
            'filters[2][operator]' => 'gte',
            'filters[2][value]' => $dateStr,
            'filters[3][path]' => 'hasGeometry.latitude',
            'filters[3][operator]' => 'gte',
            'filters[3][value]' => $minLat,
            'filters[4][path]' => 'hasGeometry.latitude',
            'filters[4][operator]' => 'lte',
            'filters[4][value]' => $maxLat,
            'filters[5][path]' => 'hasGeometry.longitude',
            'filters[5][operator]' => 'gte',
            'filters[5][value]' => $minLon,
            'filters[6][path]' => 'hasGeometry.longitude',
            'filters[6][operator]' => 'lte',
            'filters[6][value]' => $maxLon,
        ]);

        /** @var list<array<string, mixed>> $results */
        $results = \is_array($response['results'] ?? null) ? $response['results'] : (
            \is_array($response['member'] ?? null) ? $response['member'] : []
        );

        $events = [];

        foreach ($results as $result) {
            $types = (array) ($result['@type'] ?? []);
            $matchedType = array_find(self::TARGETED_TYPES, fn ($targeted): bool => \in_array($targeted, $types, true));

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

            if (!$startDate instanceof \DateTimeImmutable || !$endDate instanceof \DateTimeImmutable) {
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
     * @return list<Event>
     */
    private function fetchMarketsForStage(Stage $stage, \DateTimeImmutable $stageDate, string $locale): array
    {
        $dayOfWeek = (int) $stageDate->format('N');

        $markets = $this->marketRepository->findNearEndpoint(
            $stage->endPoint->lat,
            $stage->endPoint->lon,
            self::EVENT_RADIUS_METERS,
            $dayOfWeek,
        );

        $events = [];

        foreach ($markets as $market) {
            $startDate = $stageDate;
            $endDate = $stageDate;

            if (null !== $market->getStartTime()) {
                $startDate = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $stageDate->format('Y-m-d').' '.$market->getStartTime()) ?: $stageDate;
            }

            if (null !== $market->getEndTime()) {
                $endDate = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $stageDate->format('Y-m-d').' '.$market->getEndTime()) ?: $stageDate;
            }

            $distanceToEndPoint = $this->haversine->inMeters(
                $market->getLat(),
                $market->getLon(),
                $stage->endPoint->lat,
                $stage->endPoint->lon,
            );

            $events[] = new Event(
                name: $market->getName(),
                type: 'market',
                lat: $market->getLat(),
                lon: $market->getLon(),
                startDate: $startDate,
                endDate: $endDate,
                description: $this->translator->trans('market.weekly_description', [], 'messages', $locale),
                distanceToEndPoint: $distanceToEndPoint,
                source: 'data_gouv_markets',
            );
        }

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
            if (is_numeric($lat)) {
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
            if (is_numeric($lon)) {
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
            $first = array_first($label) ?? null;

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
            $first = array_first($desc) ?? null;

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
                if (is_numeric($price)) {
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

    /**
     * @return array<string, mixed>
     */
    private function eventToArray(Event $e): array
    {
        return [
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
        ];
    }
}
