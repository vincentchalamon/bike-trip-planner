<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Enum\ComputationName;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckResupply;
use App\Message\ScanPois;
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class ScanPoisHandler extends AbstractTripMessageHandler
{
    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        private TripRequestRepositoryInterface $tripStateManager,
        private ScannerInterface $scanner,
        private QueryBuilderInterface $queryBuilder,
        private MessageBusInterface $messageBus,
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

        $this->executeWithTracking($tripId, ComputationName::POIS, function () use ($tripId, $stages): void {
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

                $this->publisher->publish($tripId, MercureEventType::POIS_SCANNED, [
                    'stageIndex' => $i,
                    'pois' => $pois,
                ]);
            }

            $this->tripStateManager->storeStages($tripId, $stages);
            $this->messageBus->dispatch(new CheckResupply($tripId));
        });
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
        foreach (array_keys($stages) as $i) {
            $result[$i] = [];
        }

        // Pre-compute stage midpoints for fast assignment
        $midpoints = [];
        foreach ($stages as $i => $stage) {
            $geometry = $stage->geometry ?: [$stage->startPoint, $stage->endPoint];
            $mid = $geometry[(int) (\count($geometry) / 2)];
            $midpoints[$i] = $mid;
        }

        foreach ($pois as $poi) {
            $closestStage = 0;
            $closestDistance = \PHP_FLOAT_MAX;

            foreach ($midpoints as $i => $midpoint) {
                $distance = $this->haversineDistance($poi['lat'], $poi['lon'], $midpoint->lat, $midpoint->lon);
                if ($distance < $closestDistance) {
                    $closestDistance = $distance;
                    $closestStage = $i;
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
