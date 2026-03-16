<?php

declare(strict_types=1);

namespace App\Analyzer\Rules;

use App\Analyzer\StageAnalyzerInterface;
use App\ApiResource\Model\Alert;
use App\ApiResource\Stage;
use App\Engine\RiderTimeEstimatorInterface;
use App\Enum\AlertType;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Emits a WARNING alert when the estimated arrival time exceeds civil twilight end
 * (i.e. "dark enough that riding is no longer safe without lights").
 *
 * Uses PHP native date_sun_info() — no external API, no extra dependency.
 * Twilight threshold: CIVIL_TWILIGHT_END (sun 6° below horizon — still light enough to ride safely).
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

        // Compute sun information for the stage end point at the stage date
        $sunInfo = date_sun_info(
            (int) $stageDate->format('U'),
            $stage->endPoint->lat,
            $stage->endPoint->lon,
        );

        // Use civil twilight end as the "still light enough to ride" threshold
        $civilTwilightEnd = $sunInfo['civil_twilight_end'];

        // No civil twilight end means polar day (true) or polar night (false) — skip
        if (!\is_int($civilTwilightEnd)) {
            return [];
        }

        // Compute estimated arrival as decimal hour (UTC).
        // Note: departureHour is the rider's local time. Since the architecture does not
        // carry timezone information, we treat it as UTC for comparison purposes.
        // For European locations (UTC+1 to UTC+3), this may produce a conservative alert
        // (off by 1–3 h), which is acceptable for a planning tool.
        $estimatedArrival = $this->riderTimeEstimator->estimateTimeAtDistance(
            $stage->distance,
            $stage->distance,
            $departureHour,
            $averageSpeed,
            $stage->elevation,
        );

        // Convert civil twilight end timestamp to a decimal hour of the day (UTC)
        $twilightDate = new \DateTimeImmutable('@'.$civilTwilightEnd, new \DateTimeZone('UTC'));
        $twilightDecimalHours = (float) $twilightDate->format('G') + (float) $twilightDate->format('i') / 60.0;

        if ($estimatedArrival <= $twilightDecimalHours) {
            return [];
        }

        $rawSunset = $sunInfo['sunset'];
        $sunsetTimestamp = \is_int($rawSunset) ? $rawSunset : $civilTwilightEnd;
        $sunsetDate = new \DateTimeImmutable('@'.$sunsetTimestamp, new \DateTimeZone('UTC'));
        $sunsetHm = $sunsetDate->format('H:i');
        $twilightHm = $twilightDate->format('H:i');

        return [new Alert(
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
        )];
    }

    public static function getPriority(): int
    {
        return 20;
    }
}
