<?php

declare(strict_types=1);

namespace App\MessageHandler;

use Symfony\Contracts\HttpClient\ResponseInterface;
use App\Accommodation\AccommodationMetadataExtractor;
use App\Accommodation\SeasonalityCheckerInterface;
use App\AccommodationSource\AccommodationSourceRegistry;
use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\AlertType;
use App\Enum\ComputationName;
use App\Geo\GeoDistanceInterface;
use App\Geo\GeometryDistributorInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\ScanAccommodations;
use App\Repository\TripRequestRepositoryInterface;
use App\Wikidata\WikidataEnricherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final readonly class ScanAccommodationsHandler extends AbstractTripMessageHandler
{
    private const float DEDUP_DISTANCE_METERS = 200.0;

    private const int MAX_CANDIDATES_PER_STAGE = 3;

    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        TripGenerationTrackerInterface $generationTracker,
        LoggerInterface $logger,
        private TripRequestRepositoryInterface $tripStateManager,
        private AccommodationSourceRegistry $registry,
        private GeoDistanceInterface $haversine,
        private GeometryDistributorInterface $distributor,
        private AccommodationMetadataExtractor $metadataExtractor,
        private SeasonalityCheckerInterface $seasonalityChecker,
        private TranslatorInterface $translator,
        #[Autowire(service: 'accommodation_scraper.client')]
        private HttpClientInterface $scraperClient,
        private WikidataEnricherInterface $wikidataEnricher,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger, $tripStateManager);
    }

    public function __invoke(ScanAccommodations $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;
        $radiusMeters = $message->radiusMeters;
        $stageIndex = $message->stageIndex;
        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $request = $this->tripStateManager->getRequest($tripId);
        $locale = $this->tripStateManager->getLocale($tripId) ?? 'en';
        $enabledAccommodationTypes = $message->enabledAccommodationTypes;
        $isExpandScan = $message->isExpandScan;

        $this->executeWithTracking($tripId, ComputationName::ACCOMMODATIONS, function () use ($tripId, $stages, $request, $locale, $radiusMeters, $stageIndex, $enabledAccommodationTypes, $isExpandScan): void {
            // Preserve original stage keys so distributor output maps directly without re-mapping
            $stagesToProcess = (null !== $stageIndex && isset($stages[$stageIndex]))
                ? [$stageIndex => $stages[$stageIndex]]
                : $stages;

            // Use stage endpoints (not the full decimated route) so the radius applies to overnight stops only
            $endPoints = array_map(static fn (Stage $stage): Coordinate => $stage->endPoint, $stagesToProcess);

            // Fetch candidates from all enabled sources (OSM + DataTourisme + …)
            $allCandidates = $this->registry->fetchAll($endPoints, $radiusMeters, $enabledAccommodationTypes);

            // Distribute candidates to their nearest stage endpoint (output keys match $stagesToProcess keys)
            /** @var array<int, list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>, source: string, wikidataId: ?string}>> $candidatesByStage */
            $candidatesByStage = $this->distributor->distributeByEndpoint($allCandidates, $stagesToProcess);

            // Deduplicate + limit per stage BEFORE any scraping
            $retainedByStage = [];
            foreach ($candidatesByStage as $i => $candidates) {
                $deduped = $this->deduplicate($candidates);
                usort($deduped, static fn (array $a, array $b): int => $a['priceMin'] <=> $b['priceMin']);
                $retainedByStage[$i] = \array_slice($deduped, 0, self::MAX_CANDIDATES_PER_STAGE);
            }

            // Async scraping: 2 waves of parallel HTTP requests
            $retainedByStage = $this->scrapeAsync($retainedByStage);

            // Wikidata enrichment: one batch SPARQL query for all retained candidates
            $allRetained = [] !== $retainedByStage ? array_merge(...array_values($retainedByStage)) : [];
            $qIds = array_values(array_filter(array_unique(array_column($allRetained, 'wikidataId'))));
            $wikidataEnrichments = [] !== $qIds ? $this->wikidataEnricher->enrichBatch($qIds, $locale) : [];

            foreach ($retainedByStage as $i => $candidates) {
                foreach ($candidates as $j => $candidate) {
                    $qId = $candidate['wikidataId'] ?? null;
                    if (null !== $qId && isset($wikidataEnrichments[$qId])) {
                        $wikidata = $wikidataEnrichments[$qId];
                        // Wikidata never overwrites an already-filled field
                        $retainedByStage[$i][$j] = array_merge($wikidata, $candidate);
                    }
                }
            }

            // Build Accommodation DTOs, publish per stage, and store
            $startDate = $request?->startDate;
            foreach ($stagesToProcess as $i => $stage) {
                $accommodations = [];

                if ($isExpandScan) {
                    // Expand scan: keep existing accommodations, add only new unique ones
                    $existingKeys = [];
                    foreach ($stage->accommodations as $existing) {
                        $existingKeys[\sprintf('%F,%F', $existing->lat, $existing->lon)] = true;
                        $accommodations[] = [
                            'name' => $existing->name,
                            'type' => $existing->type,
                            'lat' => $existing->lat,
                            'lon' => $existing->lon,
                            'estimatedPriceMin' => $existing->estimatedPriceMin,
                            'estimatedPriceMax' => $existing->estimatedPriceMax,
                            'isExactPrice' => $existing->isExactPrice,
                            'url' => $existing->url,
                            'possibleClosed' => $existing->possibleClosed,
                            'distanceToEndPoint' => $existing->distanceToEndPoint,
                            'source' => $existing->source,
                            'description' => $existing->description,
                            'imageUrl' => $existing->imageUrl,
                            'wikipediaUrl' => $existing->wikipediaUrl,
                            'openingHours' => $existing->openingHours,
                        ];
                    }
                } else {
                    // Full scan: reset before populating
                    $stage->accommodations = [];
                    $existingKeys = [];
                }

                $stageDate = $startDate?->modify(\sprintf('+%d days', $i));
                foreach ($retainedByStage[$i] ?? [] as $raw) {
                    $key = \sprintf('%F,%F', $raw['lat'], $raw['lon']);
                    if (isset($existingKeys[$key])) {
                        continue; // skip duplicates when expanding
                    }

                    $possibleClosed = false;
                    if ($stageDate instanceof \DateTimeImmutable) {
                        $possibleClosed = false === $this->seasonalityChecker->isLikelyOpen($stageDate, $raw['tags'] ?? []);
                    }

                    $distanceToEndPoint = $this->haversine->inKilometers(
                        $raw['lat'],
                        $raw['lon'],
                        $stage->endPoint->lat,
                        $stage->endPoint->lon,
                    );

                    $accommodation = new Accommodation(
                        name: $raw['name'],
                        type: $raw['type'],
                        lat: $raw['lat'],
                        lon: $raw['lon'],
                        estimatedPriceMin: $raw['priceMin'],
                        estimatedPriceMax: $raw['priceMax'],
                        isExactPrice: $raw['isExact'],
                        url: $raw['url'],
                        possibleClosed: $possibleClosed,
                        distanceToEndPoint: $distanceToEndPoint,
                        source: $raw['source'] ?? 'osm',
                        description: $raw['description'] ?? null,
                        imageUrl: $raw['imageUrl'] ?? null,
                        wikipediaUrl: $raw['wikipediaUrl'] ?? null,
                        openingHours: $raw['openingHours'] ?? null,
                    );

                    $stage->addAccommodation($accommodation);
                    $accommodations[] = [
                        'name' => $accommodation->name,
                        'type' => $accommodation->type,
                        'lat' => $accommodation->lat,
                        'lon' => $accommodation->lon,
                        'estimatedPriceMin' => $accommodation->estimatedPriceMin,
                        'estimatedPriceMax' => $accommodation->estimatedPriceMax,
                        'isExactPrice' => $accommodation->isExactPrice,
                        'url' => $accommodation->url,
                        'possibleClosed' => $accommodation->possibleClosed,
                        'distanceToEndPoint' => $accommodation->distanceToEndPoint,
                        'source' => $accommodation->source,
                        'description' => $accommodation->description,
                        'imageUrl' => $accommodation->imageUrl,
                        'wikipediaUrl' => $accommodation->wikipediaUrl,
                        'openingHours' => $accommodation->openingHours,
                    ];
                }

                $alertsToPublish = [];
                // Warn if all detected accommodations are likely closed during this period
                if ([] !== $accommodations && array_all($accommodations, static fn (array $a): bool => true === $a['possibleClosed'])) {
                    $alert = new Alert(
                        type: AlertType::WARNING,
                        message: $this->translator->trans(
                            'alert.accommodation.seasonal_warning',
                            ['%stage%' => $stage->dayNumber],
                            'alerts',
                            $locale,
                        ),
                    );
                    $stage->addAlert($alert);
                    $alertsToPublish[] = ['type' => $alert->type->value, 'message' => $alert->message, 'lat' => $alert->lat, 'lon' => $alert->lon];
                }

                $payload = [
                    'stageIndex' => $i,
                    'accommodations' => $accommodations,
                    'searchRadiusKm' => (int) round($radiusMeters / 1000),
                ];
                if ([] !== $alertsToPublish) {
                    $payload['alerts'] = $alertsToPublish;
                }

                $this->publisher->publish($tripId, MercureEventType::ACCOMMODATIONS_FOUND, $payload);

                // Update the stage in the full stages array
                $stages[$i] = $stage;
            }

            $this->tripStateManager->storeStages($tripId, array_values($stages));
        }, $generation);
    }

    /**
     * Scrape accommodation metadata in 2 parallel waves via Symfony HttpClient multiplexing.
     *
     * Wave 1: main-page requests for all candidates with a website URL.
     * Wave 2: price-page requests for candidates whose main page had no price.
     *
     * @param array<int, list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>, source: string, wikidataId: ?string}>> $retainedByStage
     *
     * @return array<int, list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>, source: string, wikidataId: ?string}>>
     */
    private function scrapeAsync(array $retainedByStage): array
    {
        // Collect all scrapable candidates
        /** @var list<array{stageIdx: int, candidateIdx: int, url: string}> $scrapableItems */
        $scrapableItems = [];
        foreach ($retainedByStage as $stageIdx => $candidates) {
            foreach ($candidates as $candidateIdx => $candidate) {
                if ($candidate['hasWebsite'] && null !== $candidate['url']) {
                    $scrapableItems[] = [
                        'stageIdx' => $stageIdx,
                        'candidateIdx' => $candidateIdx,
                        'url' => $candidate['url'],
                    ];
                }
            }
        }

        if ([] === $scrapableItems) {
            return $retainedByStage;
        }

        // Wave 1: Fire all main-page requests (non-blocking)
        $mainResponses = [];
        foreach ($scrapableItems as $key => $item) {
            try {
                $mainResponses[$key] = $this->scraperClient->request('GET', $item['url'], ['timeout' => 3]);
            } catch (\Throwable) {
                // Skip malformed URLs
            }
        }

        // Wave 1: Collect results — Symfony HttpClient multiplexes responses concurrently
        /** @var list<array{stageIdx: int, candidateIdx: int, url: string, html: string}> $needsPricePage */
        $needsPricePage = [];
        foreach ($mainResponses as $key => $response) {
            $item = $scrapableItems[$key];
            try {
                $html = $response->getContent();
                $scraped = $this->metadataExtractor->extract($html);

                if (null !== $scraped->name) {
                    $retainedByStage[$item['stageIdx']][$item['candidateIdx']]['name'] = $scraped->name;
                }

                if (null !== $scraped->type) {
                    $retainedByStage[$item['stageIdx']][$item['candidateIdx']]['type'] = $scraped->type;
                }

                if (null !== $scraped->priceMin) {
                    $retainedByStage[$item['stageIdx']][$item['candidateIdx']]['priceMin'] = $scraped->priceMin;
                    $retainedByStage[$item['stageIdx']][$item['candidateIdx']]['priceMax'] = $scraped->priceMax ?? $scraped->priceMin;
                    $retainedByStage[$item['stageIdx']][$item['candidateIdx']]['isExact'] = true;
                } else {
                    $needsPricePage[] = [
                        'stageIdx' => $item['stageIdx'],
                        'candidateIdx' => $item['candidateIdx'],
                        'url' => $item['url'],
                        'html' => $html,
                    ];
                }
            } catch (\Throwable $e) {
                $this->logger->debug('Accommodation scraping failed.', ['url' => $item['url'], 'error' => $e->getMessage()]);
            }
        }

        if ([] === $needsPricePage) {
            /** @var array<int, list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>, source: string, wikidataId: ?string}>> $result */
            $result = $retainedByStage;

            return $result;
        }

        // Wave 2: Fire all price-page requests (non-blocking)
        /** @var list<array{stageIdx: int, candidateIdx: int, response: ResponseInterface}> $priceResponses */
        $priceResponses = [];
        foreach ($needsPricePage as $item) {
            $pricePages = $this->metadataExtractor->discoverPricePagePaths($item['html'], $item['url']);
            // Limit to 1 price page per accommodation to reduce scraping time
            foreach (\array_slice($pricePages, 0, 1) as $pricePageUrl) {
                try {
                    $priceResponses[] = [
                        'stageIdx' => $item['stageIdx'],
                        'candidateIdx' => $item['candidateIdx'],
                        'response' => $this->scraperClient->request('GET', $pricePageUrl, ['timeout' => 2]),
                    ];
                } catch (\Throwable) {
                    // Skip malformed URLs
                }
            }
        }

        // Wave 2: Collect results (first price found wins per candidate)
        /** @var array<string, true> $priceFound */
        $priceFound = [];
        foreach ($priceResponses as $priceItem) {
            $candidateKey = $priceItem['stageIdx'].'-'.$priceItem['candidateIdx'];
            if (isset($priceFound[$candidateKey])) {
                continue;
            }

            try {
                $priceHtml = $priceItem['response']->getContent();
                $extracted = $this->metadataExtractor->extractPricesFromHtml($priceHtml);
                if (null !== $extracted) {
                    $retainedByStage[$priceItem['stageIdx']][$priceItem['candidateIdx']]['priceMin'] = $extracted['priceMin'];
                    $retainedByStage[$priceItem['stageIdx']][$priceItem['candidateIdx']]['priceMax'] = $extracted['priceMax'];
                    $retainedByStage[$priceItem['stageIdx']][$priceItem['candidateIdx']]['isExact'] = true;
                    $priceFound[$candidateKey] = true;
                }
            } catch (\Throwable) {
                // Skip failed price pages
            }
        }

        /** @var array<int, list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>, source: string, wikidataId: ?string}>> $result */
        $result = $retainedByStage;

        return $result;
    }

    /**
     * @param list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>, source: string, wikidataId: ?string}> $accommodations
     *
     * @return list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>, source: string, wikidataId: ?string}>
     */
    private function deduplicate(array $accommodations): array
    {
        $kept = [];

        foreach ($accommodations as $candidate) {
            $normalizedName = mb_strtolower(trim($candidate['name']));
            $isDuplicate = false;

            foreach ($kept as $existing) {
                $existingNormalized = mb_strtolower(trim($existing['name']));
                if ($normalizedName === $existingNormalized && $this->haversine->inMeters($candidate['lat'], $candidate['lon'], $existing['lat'], $existing['lon']) < self::DEDUP_DISTANCE_METERS) {
                    $isDuplicate = true;
                    break;
                }
            }

            if (!$isDuplicate) {
                $kept[] = $candidate;
            }
        }

        return $kept;
    }
}
