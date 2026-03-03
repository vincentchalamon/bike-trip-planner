<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\Alert;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Enum\AlertType;
use App\Enum\ComputationName;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\ScanPois;
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final readonly class ScanPoisHandler extends AbstractTripMessageHandler
{
    /** @var list<string> */
    private const array RESUPPLY_CATEGORIES = [
        'restaurant', 'cafe', 'bar', 'supermarket', 'convenience',
        'bakery', 'fast_food', 'marketplace', 'butcher', 'pastry',
        'deli', 'greengrocer', 'general', 'farm',
    ];

    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        private TripRequestRepositoryInterface $tripStateManager,
        private ScannerInterface $scanner,
        private QueryBuilderInterface $queryBuilder,
        private TranslatorInterface $translator,
    ) {
        parent::__construct($computationTracker, $publisher);
    }

    public function __invoke(ScanPois $message): void
    {
        $tripId = $message->tripId;
        $stages = $this->tripStateManager->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $locale = $this->tripStateManager->getLocale($tripId) ?? 'en';

        $this->executeWithTracking($tripId, ComputationName::POIS, function () use ($tripId, $stages, $locale): void {
            // Single batched Overpass query for all stages
            $stageGeometries = array_map(
                static fn (Stage $stage): array => $stage->geometry ?: [$stage->startPoint, $stage->endPoint],
                $stages,
            );

            $query = $this->queryBuilder->buildBatchPoiQuery($stageGeometries);
            $result = $this->scanner->query($query);

            /** @var list<array{tags?: array<string, string>, lat?: float, lon?: float, center?: array{lat: float, lon: float}}> $elements */
            $elements = \is_array($result['elements'] ?? null) ? $result['elements'] : [];

            // Parse all POI elements
            $allPois = [];
            foreach ($elements as $element) {
                $tags = $element['tags'] ?? [];
                $lat = $element['lat'] ?? ($element['center']['lat'] ?? null);
                $lon = $element['lon'] ?? ($element['center']['lon'] ?? null);

                if (null === $lat || null === $lon) {
                    continue;
                }

                $category = $tags['amenity'] ?? $tags['shop'] ?? $tags['tourism'] ?? 'unknown';
                $name = $tags['name'] ?? $category;

                $allPois[] = [
                    'name' => $name,
                    'category' => $category,
                    'lat' => (float) $lat,
                    'lon' => (float) $lon,
                ];
            }

            // Distribute POIs to their nearest stage by geometry midpoint
            $poisByStage = $this->distributePoisByStage($allPois, $stages);

            foreach ($stages as $i => $stage) {
                $pois = [];
                foreach ($poisByStage[$i] ?? [] as $raw) {
                    $poi = new PointOfInterest(
                        name: $raw['name'],
                        category: $raw['category'],
                        lat: $raw['lat'],
                        lon: $raw['lon'],
                    );

                    $stage->addPoi($poi);
                    $pois[] = ['name' => $poi->name, 'category' => $poi->category];
                }

                // Lunch nudge: flag long stages with no food POIs
                $alerts = [];
                if ($stage->distance >= 40.0 && !$this->hasResupplyPoi($stage)) {
                    $alert = new Alert(
                        type: AlertType::NUDGE,
                        message: $this->translator->trans('alert.lunch.nudge', [], 'alerts', $locale),
                        lat: $stage->startPoint->lat,
                        lon: $stage->startPoint->lon,
                    );
                    $stage->addAlert($alert);
                    $alerts[] = ['type' => 'nudge', 'message' => $alert->message, 'lat' => $alert->lat, 'lon' => $alert->lon];
                }

                $payload = [
                    'stageIndex' => $i,
                    'pois' => $pois,
                ];

                if ([] !== $alerts) {
                    $payload['alerts'] = $alerts;
                }

                $this->publisher->publish($tripId, MercureEventType::POIS_SCANNED, $payload);
            }

            $this->tripStateManager->storeStages($tripId, $stages);
        });
    }

    private function hasResupplyPoi(Stage $stage): bool
    {
        return array_any($stage->pois, fn ($poi): bool => \in_array($poi->category, self::RESUPPLY_CATEGORIES, true));
    }

    /**
     * Assign each POI to the stage whose geometry is closest.
     *
     * @param list<array{name: string, category: string, lat: float, lon: float}> $pois
     * @param list<Stage>                                                         $stages
     *
     * @return array<int, list<array{name: string, category: string, lat: float, lon: float}>>
     */
    private function distributePoisByStage(array $pois, array $stages): array
    {
        /** @var array<int, list<array{name: string, category: string, lat: float, lon: float}>> $result */
        $result = [];
        /** @var array<int, list<array{lat: float, lon: float}>> $stageGeometries */
        $stageGeometries = [];
        foreach ($stages as $i => $stage) {
            $result[$i] = [];
            $geometry = $stage->geometry ?: [$stage->startPoint, $stage->endPoint];
            $stageGeometries[$i] = array_map(
                static fn (Coordinate $c): array => ['lat' => $c->lat, 'lon' => $c->lon],
                $geometry,
            );
        }

        foreach ($pois as $poi) {
            $closestStage = 0;
            $closestDistance = \PHP_FLOAT_MAX;

            foreach ($stageGeometries as $i => $geometry) {
                foreach ($geometry as $point) {
                    $distance = $this->haversineDistance($poi['lat'], $poi['lon'], $point['lat'], $point['lon']);
                    if ($distance < $closestDistance) {
                        $closestDistance = $distance;
                        $closestStage = $i;
                    }
                }
            }

            $result[$closestStage][] = $poi;
        }

        return $result;
    }

    private function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
