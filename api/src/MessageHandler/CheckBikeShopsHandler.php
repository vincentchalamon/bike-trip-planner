<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Geo\Nearest;
use App\ApiResource\Model\AlertAction;
use App\ApiResource\Model\Alert;
use App\Alert\AlertPayload;
use App\ApiResource\Model\AlertActionKind;
use App\Enum\AlertCode;
use App\Enum\AlertGroup;
use App\Enum\AlertType;
use App\Geo\GeoDistanceInterface;
use App\Mercure\MercureEventType;
use App\Message\CheckBikeShops;
use App\Osm\BikeShopRepositoryInterface;
use App\Repository\TransientTripPointsStoreInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CheckBikeShopsHandler extends AbstractTripMessageHandler
{
    private const int MINIMUM_DAYS_FOR_CHECK = 5;

    private const float BIKE_SHOP_PROXIMITY_METERS = 2000.0;

    /** Corridor half-width (m) for the local-first bike-shop reads (ADR-040); kept equal to BIKE_SHOP_PROXIMITY_METERS so the DB pre-filter always covers the per-stage proximity threshold. */
    private const int CORRIDOR_RADIUS_METERS = (int) self::BIKE_SHOP_PROXIMITY_METERS;

    public function __construct(
        TripHandlerContext $context,
        private TransientTripPointsStoreInterface $points,
        private BikeShopRepositoryInterface $bikeShopRepository,
        private GeoDistanceInterface $haversine,
    ) {
        parent::__construct($context);
    }

    public function __invoke(CheckBikeShops $message): void
    {
        $tripId = $message->tripId;
        $stages = $this->stageStore->getStages($tripId);

        if (null === $stages) {
            return;
        }

        $this->executeWithTracking($message, function () use ($tripId, $stages): void {
            // BR-06: Skip if trip is 5 days or fewer.
            //
            // "Does not apply" has to clear, not just skip (ADR-068): a long trip shortened to
            // five days re-dispatches this check, and a short-circuit that only marked the
            // computation done would leave the previous alerts standing for good — this branch
            // keeps firing on every later edit, so nothing would ever come back to remove them.
            //
            // Inside the tracking, not before it: settling this computation is what may
            // complete the trip, and only executeWithTracking() evaluates the completion gate.
            if (\count($stages) <= self::MINIMUM_DAYS_FOR_CHECK) {
                $this->stageStore->updateTripAlertsForGroup($tripId, AlertGroup::BIKE_SHOP, []);
                $this->publisher->publish($tripId, MercureEventType::BIKE_SHOP_ALERTS, ['alerts' => []]);

                return;
            }

            // Read bike shops from the local-first index along the route corridor (ADR-040).
            $route = $this->routeCorridor($this->points, $tripId, $stages);

            // Parse bike shop locations, distinguishing repair shops from sale-only shops
            $repairShopLocations = [];
            $saleOnlyShopLocations = [];
            foreach ($this->bikeShopRepository->findInCorridor($route, self::CORRIDOR_RADIUS_METERS) as $shop) {
                if ($shop['hasRepair']) {
                    $repairShopLocations[] = ['lat' => $shop['lat'], 'lon' => $shop['lon']];
                } else {
                    $saleOnlyShopLocations[] = ['lat' => $shop['lat'], 'lon' => $shop['lon']];
                }
            }

            // Check each stage for nearby bike shops
            $stagesWithoutBikeShop = [];
            foreach ($stages as $stage) {
                // A rest day is not ridden: no mid-ride mechanical failure to cover.
                if ($stage->isRestDay) {
                    continue;
                }

                $midpoint = $stage->midpoint();

                if (Nearest::anyWithin($this->haversine, $midpoint, $repairShopLocations, self::BIKE_SHOP_PROXIMITY_METERS)) {
                    continue;
                }

                $allShops = [...$repairShopLocations, ...$saleOnlyShopLocations];
                $nearestShop = Nearest::to($this->haversine, $midpoint, $allShops);
                $stagesWithoutBikeShop[] = AlertPayload::forStage($stage, new Alert(
                    code: AlertCode::BIKE_SHOP_NONE_NEARBY,
                    type: AlertType::NUDGE,
                    messageKey: 'alert.bike_shop.nudge',
                    action: null !== $nearestShop
                        ? new AlertAction(AlertActionKind::NAVIGATE, 'alert.bike_shop.action', ['lat' => $nearestShop['lat'], 'lon' => $nearestShop['lon']])
                        : null,
                ));
            }

            // Same array to the database and to the wire (ADR-068): grouped by the stage
            // it addresses, and without `stageId`/`dayNumber` — the first is the key, the
            // second is renumbered by every structural edit and is derived on read.
            $this->stageStore->updateTripAlertsForGroup($tripId, AlertGroup::BIKE_SHOP, $this->groupByStage($stagesWithoutBikeShop));

            $this->publisher->publish($tripId, MercureEventType::BIKE_SHOP_ALERTS, [
                'alerts' => $this->renderForWire($tripId, $stagesWithoutBikeShop),
            ]);
        });
    }
}
