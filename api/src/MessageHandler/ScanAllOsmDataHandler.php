<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Enum\ComputationName;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\ScanAllOsmData;
use App\Repository\TripRequestRepositoryInterface;
use App\Scanner\QueryBuilderInterface;
use App\Scanner\ScannerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ScanAllOsmDataHandler extends AbstractTripMessageHandler
{
    public function __construct(
        ComputationTrackerInterface $computationTracker,
        TripUpdatePublisherInterface $publisher,
        private TripRequestRepositoryInterface $tripStateManager,
        private ScannerInterface $scanner,
        private QueryBuilderInterface $queryBuilder,
    ) {
        parent::__construct($computationTracker, $publisher);
    }

    public function __invoke(ScanAllOsmData $message): void
    {
        $tripId = $message->tripId;

        $this->executeWithTracking($tripId, ComputationName::OSM_SCAN, function () use ($tripId): void {
            $decimatedData = $this->tripStateManager->getDecimatedPoints($tripId);

            if (null === $decimatedData) {
                return;
            }

            $points = array_map(
                static fn (array $p): Coordinate => new Coordinate($p['lat'], $p['lon'], $p['ele']),
                $decimatedData,
            );

            // Execute all 5 Overpass queries concurrently — results are cached.
            // Leaf handlers will hit the cache when they build the same queries.
            $this->scanner->queryBatch([
                'poi' => $this->queryBuilder->buildPoiQuery($points),
                'accommodation' => $this->queryBuilder->buildAccommodationQuery($points),
                'bikeShop' => $this->queryBuilder->buildBikeShopQuery($points),
                'cemetery' => $this->queryBuilder->buildCemeteryQuery($points),
                'ways' => $this->queryBuilder->buildWaysQuery($points),
            ]);
        });
    }
}
