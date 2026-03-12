<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Accommodation\AccommodationMetadataExtractor;
use App\Accommodation\SeasonalityCheckerInterface;
use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Engine\PricingHeuristicEngine;
use App\Enum\AlertType;
use App\Enum\ComputationName;
use App\Geo\GeoDistanceInterface;
use App\Geo\GeometryDistributorInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\ScanAccommodations;
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// @todo #89 SRP: extract OsmAccommodationParser, AccommodationDeduplicator, AccommodationScraper
#[AsMessageHandler]
final readonly class ScanAccommodationsHandler extends AbstractTripMessageHandler
{
    private const float DEDUP_DISTANCE_METERS = 200.0;

    private const int MAX_CANDIDATES_PER_STAGE = 3;

    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        private TripRequestRepositoryInterface $tripStateManager,
        private ScannerInterface $scanner,
        private QueryBuilderInterface $queryBuilder,
        private PricingHeuristicEngine $pricingEngine,
        private GeoDistanceInterface $haversine,
        private GeometryDistributorInterface $distributor,
        private AccommodationMetadataExtractor $metadataExtractor,
        private SeasonalityCheckerInterface $seasonalityChecker,
        private TranslatorInterface $translator,
        #[Autowire(service: 'accommodation_scraper.client')]
        private HttpClientInterface $scraperClient,
        private LoggerInterface $logger,
    ) {
        parent::__construct($computationTracker, $publisher);
    }

    public function __invoke(ScanAccommodations $message): void
    {
        $tripId = $message->tripId;
        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $request = $this->tripStateManager->getRequest($tripId);
        $locale = $this->tripStateManager->getLocale($tripId) ?? 'en';

        $this->executeWithTracking($tripId, ComputationName::ACCOMMODATIONS, function () use ($tripId, $stages, $request, $locale): void {
            // Single Overpass query using decimated route points (shared cache key with ScanAllOsmDataHandler)
            $decimatedData = $this->tripStateManager->getDecimatedPoints($tripId);
            $points = null !== $decimatedData
                ? array_map(static fn (array $p): Coordinate => new Coordinate($p['lat'], $p['lon'], $p['ele']), $decimatedData)
                : array_map(static fn (Stage $stage): Coordinate => $stage->endPoint, $stages);

            $query = $this->queryBuilder->buildAccommodationQuery($points);
            $result = $this->scanner->query($query);

            /** @var list<array{id?: int, type?: string, tags?: array<string, string>, lat?: float, lon?: float, center?: array{lat: float, lon: float}}> $elements */
            $elements = \is_array($result['elements'] ?? null) ? $result['elements'] : [];

            // Phase 1: Parse OSM elements into candidates (no HTTP)
            $allCandidates = $this->parseOsmElements($elements);

            // Distribute candidates to their nearest stage endpoint
            /** @var array<int, list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>}>> $candidatesByStage */
            $candidatesByStage = $this->distributor->distributeByEndpoint($allCandidates, $stages);

            // Deduplicate + limit per stage BEFORE any scraping
            $retainedByStage = [];
            foreach ($candidatesByStage as $i => $candidates) {
                $deduped = $this->deduplicate($candidates);
                usort($deduped, static fn (array $a, array $b): int => $a['priceMin'] <=> $b['priceMin']);
                $retainedByStage[$i] = \array_slice($deduped, 0, self::MAX_CANDIDATES_PER_STAGE);
            }

            // Async scraping: 2 waves of parallel HTTP requests
            $retainedByStage = $this->scrapeAsync($retainedByStage);

            // Build Accommodation DTOs, publish per stage, and store
            $startDate = $request?->startDate;
            foreach ($stages as $i => $stage) {
                $accommodations = [];
                $stageDate = $startDate?->modify(\sprintf('+%d days', $i));
                foreach ($retainedByStage[$i] ?? [] as $raw) {
                    $possibleClosed = false;
                    if (null !== $stageDate) {
                        $possibleClosed = false === $this->seasonalityChecker->isLikelyOpen($stageDate, $raw['tags'] ?? []);
                    }

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
                    $alertsToPublish[] = ['type' => $alert->type->value, 'message' => $alert->message];
                }

                $payload = [
                    'stageIndex' => $i,
                    'accommodations' => $accommodations,
                ];
                if ([] !== $alertsToPublish) {
                    $payload['alerts'] = $alertsToPublish;
                }

                $this->publisher->publish($tripId, MercureEventType::ACCOMMODATIONS_FOUND, $payload);
            }

            $this->tripStateManager->storeStages($tripId, $stages);
        });
    }

    /**
     * Parse OSM elements into candidate arrays without any HTTP requests.
     *
     * @param list<array{id?: int, type?: string, tags?: array<string, string>, lat?: float, lon?: float, center?: array{lat: float, lon: float}}> $elements
     *
     * @return list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>}>
     */
    private function parseOsmElements(array $elements): array
    {
        $candidates = [];

        foreach ($elements as $element) {
            $tags = $element['tags'] ?? [];
            $lat = $element['lat'] ?? ($element['center']['lat'] ?? null);
            $lon = $element['lon'] ?? ($element['center']['lon'] ?? null);

            if (null === $lat || null === $lon) {
                continue;
            }

            $url = $tags['website']
                ?? $tags['contact:website']
                ?? (isset($element['id'], $element['type'])
                    ? \sprintf('https://www.openstreetmap.org/%s/%d', $element['type'], $element['id'])
                    : null);

            $type = $tags['tourism'] ?? 'hotel';
            $name = $tags['name'] ?? $type;
            $tagCount = \count($tags);
            $pricing = $this->pricingEngine->estimatePrice($type, $tags);

            $candidates[] = [
                'name' => $name,
                'type' => $type,
                'lat' => (float) $lat,
                'lon' => (float) $lon,
                'priceMin' => $pricing['min'],
                'priceMax' => $pricing['max'],
                'isExact' => $pricing['isExact'],
                'url' => $url,
                'tagCount' => $tagCount,
                'hasWebsite' => isset($tags['website']) || isset($tags['contact:website']),
                'tags' => $tags,
            ];
        }

        return $candidates;
    }

    /**
     * Scrape accommodation metadata in 2 parallel waves via Symfony HttpClient multiplexing.
     *
     * Wave 1: main-page requests for all candidates with a website URL.
     * Wave 2: price-page requests for candidates whose main page had no price.
     *
     * @param array<int, list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>}>> $retainedByStage
     *
     * @return array<int, list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>}>>
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
                $mainResponses[$key] = $this->scraperClient->request('GET', $item['url'], ['timeout' => 5]);
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
            /** @var array<int, list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>}>> $result */
            $result = $retainedByStage;

            return $result;
        }

        // Wave 2: Fire all price-page requests (non-blocking)
        /** @var list<array{stageIdx: int, candidateIdx: int, response: \Symfony\Contracts\HttpClient\ResponseInterface}> $priceResponses */
        $priceResponses = [];
        foreach ($needsPricePage as $item) {
            $pricePages = $this->metadataExtractor->discoverPricePagePaths($item['html'], $item['url']);
            // Limit to 1 price page per accommodation to reduce scraping time
            foreach (\array_slice($pricePages, 0, 1) as $pricePageUrl) {
                try {
                    $priceResponses[] = [
                        'stageIdx' => $item['stageIdx'],
                        'candidateIdx' => $item['candidateIdx'],
                        'response' => $this->scraperClient->request('GET', $pricePageUrl, ['timeout' => 3]),
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

        /** @var array<int, list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>}>> $result */
        $result = $retainedByStage;

        return $result;
    }

    /**
     * @param list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>}> $accommodations
     *
     * @return list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>}>
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
