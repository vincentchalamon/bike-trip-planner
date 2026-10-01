<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\Alert;
use App\Alert\AlertPayload;
use App\Accommodation\CandidateRanker;
use App\Accommodation\SeasonalityCheckerInterface;
use App\AccommodationSource\AccommodationSourceRegistry;
use App\ApiResource\Model\Accommodation;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\Entity\User;
use App\Enum\AlertCode;
use App\Enum\AlertGroup;
use App\Enum\AlertType;
use App\Geo\GeoDistanceInterface;
use App\Geo\GeometryDistributorInterface;
use App\Mapper\StageArrayMapper;
use App\Mercure\MercureEventType;
use App\Message\ScanAccommodations;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ScanAccommodationsHandler extends AbstractTripMessageHandler
{
    /**
     * Candidates retained per stage. Raised from 3 to 5 with the completeness
     * ranking (#869): the per-family diversity guard spends one of the slots on
     * the minority family, so three left the rider two real options for a whole
     * night; and the pool is bounded upstream at 30 rows per end point (#868), so
     * five still keeps a 6x margin. The panel renders the list (re-sorted by
     * distance client-side) and has no fixed number of cards, so widening the
     * choice costs two JSONB entries per stage and no layout change.
     */
    private const int MAX_CANDIDATES_PER_STAGE = 5;

    public function __construct(
        TripHandlerContext $context,
        private AccommodationSourceRegistry $registry,
        private GeoDistanceInterface $haversine,
        private GeometryDistributorInterface $distributor,
        private SeasonalityCheckerInterface $seasonalityChecker,
        private CandidateRanker $ranker,
        private StageArrayMapper $stageMapper,
    ) {
        parent::__construct($context);
    }

    public function __invoke(ScanAccommodations $message): void
    {
        $tripId = $message->tripId;
        $radiusMeters = $message->radiusMeters;
        $stages = $this->stageStore->getStages($tripId);

        if (null === $stages) {
            return;
        }

        // Resolve the targeted stage by identity, keeping its current position as the key
        // the distributor and the published payload are built on.
        $stageIndex = null;
        if (null !== $message->stageId) {
            foreach ($stages as $index => $stage) {
                if ($stage->id === $message->stageId) {
                    $stageIndex = $index;
                    break;
                }
            }

            if (null === $stageIndex) {
                return;
            }
        }

        $request = $this->tripRequestRepository->getRequest($tripId);
        $enabledAccommodationTypes = $message->enabledAccommodationTypes;
        $isExpandScan = $message->isExpandScan;

        $this->executeWithTracking($message, function () use ($tripId, $stages, $request, $radiusMeters, $stageIndex, $enabledAccommodationTypes, $isExpandScan): void {
            // Preserve original stage keys so distributor output maps directly without re-mapping
            $stagesToProcess = (null !== $stageIndex && isset($stages[$stageIndex]))
                ? [$stageIndex => $stages[$stageIndex]]
                : $stages;

            // Use stage endpoints (not the full decimated route) so the radius applies to overnight stops only
            $endPoints = array_map(static fn (Stage $stage): Coordinate => $stage->endPoint, $stagesToProcess);

            // Fetch candidates from all enabled sources (OSM + DataTourisme + …)
            $allCandidates = $this->registry->fetchAll($endPoints, $radiusMeters, $enabledAccommodationTypes);

            // Distribute candidates to their nearest stage endpoint (output keys match $stagesToProcess keys)
            /** @var array<int, list<array{name: string, type: string, lat: float, lon: float, priceMin: float, priceMax: float, isExact: bool, url: ?string, tagCount: int, hasWebsite: bool, tags: array<string, string>, stars?: ?int, capacity?: ?int, fee?: ?string, source?: string, wikidataId?: ?string, description?: ?string, imageUrl?: ?string, wikipediaUrl?: ?string, openingHours?: ?string, phone?: ?string, osmType?: ?string, osmId?: ?int}>> $candidatesByStage */
            $candidatesByStage = $this->distributor->distributeByEndpoint($allCandidates, $stagesToProcess);

            // Rank + limit per stage; the sources' doubles were collapsed by fetchAll().
            // Prices are already set by each source at fetch time: structured open
            // data (DataTourisme priceSpecification, OSM charge/fee/stars) or the
            // PricingHeuristicEngine fallback (type/region/stars). No live HTML
            // scraping (ADR-040). Ranking is by completeness with price as the
            // tiebreaker, plus a per-family diversity guard — see CandidateRanker.
            $retainedByStage = [];
            foreach ($candidatesByStage as $i => $candidates) {
                $retainedByStage[$i] = $this->ranker->rank($candidates, self::MAX_CANDIDATES_PER_STAGE);
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
                        $accommodations[] = $this->stageMapper->accommodation($existing);
                    }
                } else {
                    // Full scan: reset before populating
                    $stage->accommodations = [];
                    $existingKeys = [];
                }

                $stageDate = $startDate instanceof \DateTimeImmutable ? $stage->dateFrom($startDate) : null;
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
                        phone: $raw['phone'] ?? null,
                        osmType: $raw['osmType'] ?? null,
                        osmId: $raw['osmId'] ?? null,
                    );

                    $stage->addAccommodation($accommodation);
                    $accommodations[] = $this->stageMapper->accommodation($accommodation);
                }

                $alertsToPublish = [];
                // Warn if all detected accommodations are likely closed during this period
                if ([] !== $accommodations && array_all($accommodations, static fn (array $a): bool => $a['possibleClosed'])) {
                    $alertsToPublish[] = AlertPayload::of(new Alert(
                        code: AlertCode::ACCOMMODATION_SEASONAL_CLOSURE,
                        type: AlertType::WARNING,
                        messageKey: 'alert.accommodation.seasonal_warning',
                    ));
                }

                // Same array to both consumers (ADR-068), the empty one included: a rerun that
                // finds nothing has to clear the previous alerts on a live client too, or the
                // database and the open page disagree until a reload.
                $this->stageStore->updateStageAlertsForGroup($tripId, $stage->id, AlertGroup::ACCOMMODATIONS, $alertsToPublish);
                $this->publisher->publish($tripId, MercureEventType::ACCOMMODATIONS_FOUND, [
                    'stageId' => $stage->id,
                    'accommodations' => $accommodations,
                    'searchRadiusKm' => (int) round($radiusMeters / 1000),
                    'alerts' => $this->alertRenderer->render($alertsToPublish, $stage->dayNumber, $this->tripRequestRepository->getLocale($tripId) ?? User::FALLBACK_LOCALE),
                ]);
            }

            // Persist accommodations with an atomic per-column UPDATE, only for the
            // processed stage(s) (single-stage expand or all) — recette #649. The
            // seasonal alert is delivered live via Mercure (above), not persisted here.
            foreach ($stagesToProcess as $stage) {
                $this->stageStore->updateStageAccommodations($tripId, $stage->id, array_values($stage->accommodations));
            }
        });
    }
}
