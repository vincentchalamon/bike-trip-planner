<?php

declare(strict_types=1);

namespace App\Analyzer\Rules;

use App\Analyzer\StageAnalyzerInterface;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\AlertAction;
use App\ApiResource\Model\AlertActionKind;
use App\ApiResource\Stage;
use App\Engine\RiderTimeEstimatorInterface;
use App\Enum\AlertCode;
use App\Enum\AlertType;
use App\Geo\TimezoneResolverInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Emits a WARNING alert when the estimated arrival time exceeds civil twilight end
 * (i.e. "dark enough that riding is no longer safe without lights").
 *
 * Uses PHP native date_sun_info() — no external API, no extra dependency.
 * Twilight threshold: CIVIL_TWILIGHT_END (sun 6° below horizon — still light enough to ride safely).
 *
 * Every hour handled here is the rider's local time at the stage end point: the
 * timezone is resolved from the coordinates (TimezoneResolver), so the comparison
 * against `departureHour` (local by definition) is homogeneous and the displayed
 * sunset/twilight times match what the rider sees out the window.
 *
 * Context keys consumed:
 *  - 'startDate'     (\DateTimeImmutable|null) — trip start date; falls back to today
 *  - 'stageIndex'    (int)                     — 0-based stage index; used to offset startDate
 *  - 'departureHour' (int)                     — rider departure hour (default 8)
 *  - 'averageSpeed'  (float)                   — rider average speed km/h (default 15.0)
 *  - 'locale'        (string)                  — translation locale (default 'en')
 */
final readonly class SunsetAlertAnalyzer implements StageAnalyzerInterface
{
    public function __construct(
        private RiderTimeEstimatorInterface $riderTimeEstimator,
        private TranslatorInterface $translator,
        private TimezoneResolverInterface $timezoneResolver,
    ) {
    }

    public function analyze(Stage $stage, array $context = []): array
    {
        if ($stage->isRestDay) {
            return [];
        }

        /** @var \DateTimeImmutable|null $startDate */
        $startDate = $context['startDate'] ?? null;
        /** @var int $stageIndex */
        $stageIndex = $context['stageIndex'] ?? 0;
        /** @var int $departureHour */
        $departureHour = $context['departureHour'] ?? 8;
        /** @var float $averageSpeed */
        $averageSpeed = $context['averageSpeed'] ?? 15.0;
        /** @var string $locale */
        $locale = $context['locale'] ?? 'en';

        // Compute the stage date from the start date + stage index offset
        $baseDate = $startDate ?? new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $stageDate = $baseDate->modify(\sprintf('+%d days', $stageIndex));

        if (false === $stageDate) {
            return [];
        }

        // The rider's timezone at the stage end point: everything below is local time.
        $timezone = $this->timezoneResolver->resolve($stage->endPoint->lat, $stage->endPoint->lon);

        // Compute sun information for the stage end point at local noon on the stage
        // date, so the solar day matches the calendar day the rider planned.
        $localNoon = new \DateTimeImmutable($stageDate->format('Y-m-d').' 12:00:00', $timezone);

        $sunInfo = date_sun_info(
            $localNoon->getTimestamp(),
            $stage->endPoint->lat,
            $stage->endPoint->lon,
        );

        // Use civil twilight end as the "still light enough to ride" threshold
        $civilTwilightEnd = $sunInfo['civil_twilight_end'];

        // No civil twilight end means polar day (true) or polar night (false) — skip
        if (!\is_int($civilTwilightEnd)) {
            return [];
        }

        // Estimated arrival as a decimal hour, derived from departureHour (local time).
        $estimatedArrival = $this->riderTimeEstimator->estimateTimeAtDistance(
            $stage->distance,
            $stage->distance,
            $departureHour,
            $averageSpeed,
            $stage->elevation,
        );

        // Convert the civil twilight end timestamp to a local decimal hour of the day
        $twilightDate = new \DateTimeImmutable('@'.$civilTwilightEnd)->setTimezone($timezone);
        $twilightDecimalHours = (float) $twilightDate->format('G') + (float) $twilightDate->format('i') / 60.0;

        if ($estimatedArrival <= $twilightDecimalHours) {
            return [];
        }

        $rawSunset = $sunInfo['sunset'];
        $sunsetTimestamp = \is_int($rawSunset) ? $rawSunset : $civilTwilightEnd;
        $sunsetDate = new \DateTimeImmutable('@'.$sunsetTimestamp)->setTimezone($timezone);
        $sunsetHm = $sunsetDate->format('H:i');
        $twilightHm = $twilightDate->format('H:i');

        // Compute suggested earlier departure: shift departure so arrival matches twilight
        $ridingDuration = $estimatedArrival - $departureHour;
        $suggestedDeparture = max(5, (int) floor($twilightDecimalHours - $ridingDuration));

        return [new Alert(
            code: AlertCode::SUNSET_ARRIVAL_AFTER_TWILIGHT,
            type: AlertType::WARNING,
            message: $this->translator->trans(
                'alert.sunset.warning',
                [
                    '%stage%' => $stage->dayNumber,
                    '%sunset%' => $sunsetHm,
                    '%twilight%' => $twilightHm,
                ],
                'alerts',
                $locale,
            ),
            lat: $stage->endPoint->lat,
            lon: $stage->endPoint->lon,
            action: new AlertAction(
                kind: AlertActionKind::AUTO_FIX,
                label: $this->translator->trans('alert.sunset.action', [], 'alerts', $locale),
                payload: ['departureHour' => $suggestedDeparture],
            ),
        )];
    }

    public static function getPriority(): int
    {
        return 20;
    }
}
