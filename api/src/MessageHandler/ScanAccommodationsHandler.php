<?php

declare(strict_types=1);

namespace App\MessageHandler;

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
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
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
        private SeasonalityCheckerInterface $seasonalityChecker,
        private TranslatorInterface $translator,
        MessageBusInterface $messageBus,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger, $tripStateManager, $messageBus);
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
            /** @var array<int, list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>, source: string, wikidataId: ?string, description: ?string, imageUrl: ?string, wikipediaUrl: ?string, openingHours: ?string}>> $candidatesByStage */
            $candidatesByStage = $this->distributor->distributeByEndpoint($allCandidates, $stagesToProcess);

            // Deduplicate + limit per stage. Prices are already set by each
            // source at fetch time: structured open data (DataTourisme
            // priceSpecification, OSM charge/fee) or the PricingHeuristicEngine
            // fallback (type/region). No live HTML scraping (ADR-040).
            $retainedByStage = [];
            foreach ($candidatesByStage as $i => $candidates) {
                $deduped = $this->deduplicate($candidates);
                usort($deduped, static fn (array $a, array $b): int => $a['priceMin'] <=> $b['priceMin']);
                $retainedByStage[$i] = \array_slice($deduped, 0, self::MAX_CANDIDATES_PER_STAGE);
            }

            // Wikidata enrichment (description, image, Wikipedia URL) is baked into
            // the local index at provision time (ADR-041); the candidates already
            // carry it, so there is no runtime SPARQL pass here.

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
            }

            // Persist accommodations with an atomic per-column UPDATE, only for the
            // processed stage(s) (single-stage expand or all) — recette #649. The
            // seasonal alert is delivered live via Mercure (above), not persisted here.
            foreach ($stagesToProcess as $stage) {
                $this->tripStateManager->updateStageAccommodations($tripId, $stage->dayNumber, array_values($stage->accommodations));
            }
        }, $generation);
    }

    /**
     * @param list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>, source: string, wikidataId: ?string, description: ?string, imageUrl: ?string, wikipediaUrl: ?string, openingHours: ?string}> $accommodations
     *
     * @return list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>, source: string, wikidataId: ?string, description: ?string, imageUrl: ?string, wikipediaUrl: ?string, openingHours: ?string}>
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
