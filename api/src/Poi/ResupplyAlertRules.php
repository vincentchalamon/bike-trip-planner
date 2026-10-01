<?php

declare(strict_types=1);

namespace App\Poi;

use App\Alert\AlertPayload;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\PointOfInterest;
use App\ApiResource\Stage;
use App\Engine\FixedSchedule;
use App\Engine\OpeningHours;
use App\Engine\RiderTimeEstimatorInterface;
use App\Enum\AlertCode;
use App\Enum\AlertType;

/**
 * The two resupply alerts of a ridden stage: no food anywhere on a long stage, and every food
 * stop known to be closed when the rider goes past.
 *
 * Both are about passing through while riding, so a rest day raises neither — its POIs are
 * still scanned and published, they are useful on the spot.
 */
final readonly class ResupplyAlertRules
{
    /**
     * The categories a rider can eat or buy food at.
     *
     * @var list<string>
     */
    public const array RESUPPLY_CATEGORIES = [
        'restaurant', 'cafe', 'bar', 'supermarket', 'convenience',
        'bakery', 'fast_food', 'marketplace', 'butcher', 'pastry',
        'deli', 'greengrocer', 'general', 'farm', 'fuel',
    ];

    private const float LUNCH_NUDGE_DISTANCE_KM = 40.0;

    public function __construct(
        private SupplyTimelineBuilder $supplyTimelineBuilder,
        private RiderTimeEstimatorInterface $riderTimeEstimator,
    ) {
    }

    public static function isResupply(string $category): bool
    {
        return \in_array($category, self::RESUPPLY_CATEGORIES, true);
    }

    /**
     * @param list<PointOfInterest> $pois                every POI of the stage corridor
     * @param list<Coordinate>      $geometry            the stage line the POIs are placed on
     * @param list<float>           $cumulativeDistances km from the stage start at each vertex of $geometry
     * @param int|null              $isoWeekday          1 (Monday) to 7 (Sunday), null when the trip has no start date
     *
     * @return list<array<string, mixed>>
     */
    public function alertsFor(Stage $stage, array $pois, array $geometry, array $cumulativeDistances, int $departureHour, float $averageSpeed, ?int $isoWeekday): array
    {
        if ($stage->isRestDay) {
            return [];
        }

        $alerts = [];
        if ($stage->distance >= self::LUNCH_NUDGE_DISTANCE_KM && !array_any($pois, static fn (PointOfInterest $poi): bool => self::isResupply($poi->category))) {
            $alerts[] = AlertPayload::of(new Alert(
                code: AlertCode::RESUPPLY_NONE_ON_STAGE,
                type: AlertType::NUDGE,
                messageKey: 'alert.lunch.nudge',
                lat: $stage->startPoint->lat,
                lon: $stage->startPoint->lon,
            ));
        }

        if ($this->allResupplyPoisAreClosed($pois, $stage, $geometry, $cumulativeDistances, $departureHour, $averageSpeed, $isoWeekday)) {
            $alerts[] = AlertPayload::of(new Alert(
                code: AlertCode::RESUPPLY_CLOSED_AT_PASSAGE,
                type: AlertType::WARNING,
                messageKey: 'alert.resupply.timing_warning',
                lat: $stage->startPoint->lat,
                lon: $stage->startPoint->lon,
            ));
        }

        return $alerts;
    }

    /**
     * True only when every resupply POI on the stage is *known* to be closed at the estimated
     * rider passage time.
     *
     * A single POI that is open — or whose hours cannot be established — makes the stage
     * inconclusive and suppresses the warning: it used to be raised from the category-typical
     * slots alone, i.e. from schedules nobody had checked (#875).
     *
     * @param list<PointOfInterest> $pois
     * @param list<Coordinate>      $geometry
     * @param list<float>           $cumulativeDistances
     */
    private function allResupplyPoisAreClosed(array $pois, Stage $stage, array $geometry, array $cumulativeDistances, int $departureHour, float $averageSpeed, ?int $isoWeekday): bool
    {
        $closed = 0;

        foreach ($pois as $poi) {
            if (!self::isResupply($poi->category)) {
                continue;
            }

            $nearestIndex = $this->supplyTimelineBuilder->findNearestGeometryIndex($geometry, $poi->lat, $poi->lon);
            $estimatedTime = $this->riderTimeEstimator->estimateTimeAtDistance($cumulativeDistances[$nearestIndex], $stage->distance, $departureHour, $averageSpeed, $stage->elevation);

            if (false !== $this->isOpenAt($poi, $estimatedTime, $isoWeekday)) {
                return false;
            }

            ++$closed;
        }

        return $closed > 0;
    }

    /**
     * Tri-state openness of a POI: true = open, false = closed, null = unknown.
     *
     * The real OSM `opening_hours` wins whenever it is present and understood. Without it, the
     * category-typical {@see FixedSchedule} is only allowed to answer "probably open" — never
     * "closed", which would put an invented schedule behind a user-facing warning.
     */
    private function isOpenAt(PointOfInterest $poi, float $decimalHour, ?int $isoWeekday): ?bool
    {
        if (null !== $poi->openingHours) {
            return OpeningHours::parse($poi->openingHours)?->isOpenAt($decimalHour, $isoWeekday);
        }

        return FixedSchedule::forCategory($poi->category)->isOpenAt($decimalHour) ? true : null;
    }
}
