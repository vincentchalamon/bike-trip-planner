<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\TripRequest;
use App\ApiResource\Model\Coordinate;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Enum\ComputationName;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\ScanAllOsmData;
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ScanAllOsmDataHandler extends AbstractTripMessageHandler
{
    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        TripGenerationTrackerInterface $generationTracker,
        LoggerInterface $logger,
        private TripRequestRepositoryInterface $tripStateManager,
        private ScannerInterface $scanner,
        private QueryBuilderInterface $queryBuilder,
    ) {
        parent::__construct($computationTracker, $publisher, $generationTracker, $logger);
    }

    public function __invoke(ScanAllOsmData $message): void
    {
        $tripId = $message->tripId;
        $generation = $message->generation;

        $this->executeWithTracking($tripId, ComputationName::OSM_SCAN, function () use ($tripId): void {
            $decimatedData = $this->tripStateManager->getDecimatedPoints($tripId);

            if (null === $decimatedData) {
                return;
            }

            $points = array_map(
                static fn (array $p): Coordinate => new Coordinate($p['lat'], $p['lon'], $p['ele']),
                $decimatedData,
            );

            $request = $this->tripStateManager->getRequest($tripId);
            \assert($request instanceof TripRequest);
            $enabledAccommodationTypes = $request->enabledAccommodationTypes;

            // Execute all 5 Overpass queries concurrently — results are cached.
            // Leaf handlers will hit the cache when they build the same queries.
            $this->scanner->queryBatch([
                'poi' => $this->queryBuilder->buildPoiQuery($points),
                'accommodation' => $this->queryBuilder->buildAccommodationQuery($points, QueryBuilderInterface::DEFAULT_ACCOMMODATION_RADIUS_METERS, $enabledAccommodationTypes),
                'bikeShop' => $this->queryBuilder->buildBikeShopQuery($points),
                'cemetery' => $this->queryBuilder->buildCemeteryQuery($points),
                'ways' => $this->queryBuilder->buildWaysQuery($points),
            ]);
        }, $generation);
    }
}
