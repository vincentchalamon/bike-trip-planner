<?php

declare(strict_types=1);

namespace App\Analyzer\Rules;

use App\Analyzer\StageAnalyzerInterface;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\AlertAction;
use App\ApiResource\Model\AlertActionKind;
use App\ApiResource\Stage;
use App\Enum\AlertCode;
use App\Enum\AlertParameterFormat;
use App\Enum\AlertType;

final readonly class TrafficDangerAnalyzer implements StageAnalyzerInterface
{
    /** @var list<string> */
    private const array CRITICAL_HIGHWAYS = ['primary', 'trunk'];

    /** @var list<string> */
    private const array WARNING_HIGHWAYS = ['secondary'];

    private const int MIN_SEGMENT_LENGTH = 500;

    private const int NUDGE_MAX_SPEED = 50;

    public function analyze(Stage $stage, array $context = []): array
    {
        // A rest day is not ridden: its traffic exposure is irrelevant.
        if ($stage->isRestDay) {
            return [];
        }

        /** @var list<array{highway?: string, cycleway?: string, 'cycleway:right'?: string, 'cycleway:left'?: string, 'cycleway:both'?: string, bicycle?: string, maxspeed?: string, length?: float, lat?: float, lon?: float, geometry?: list<list<array{0: float, 1: float}>>}> $osmWays */
        $osmWays = $context['osmWays'] ?? [];

        $criticalSegments = [];
        $warningSegments = [];
        $nudgeSegments = [];

        foreach ($osmWays as $way) {
            $highway = $way['highway'] ?? '';

            $isCritical = \in_array($highway, self::CRITICAL_HIGHWAYS, true);
            $isWarning = \in_array($highway, self::WARNING_HIGHWAYS, true);

            if (!$isCritical && !$isWarning) {
                continue;
            }

            if ($this->hasCycleInfrastructure($way)) {
                continue;
            }

            $length = $way['length'] ?? 0.0;
            if ($length < self::MIN_SEGMENT_LENGTH) {
                continue;
            }

            if ($isCritical) {
                $criticalSegments[] = $way;
            } else {
                // An absent or unreadable maxspeed is missing data, not a danger: it stays a NUDGE.
                $maxspeed = $this->parseMaxspeed($way['maxspeed'] ?? '');
                if (null !== $maxspeed && $maxspeed > self::NUDGE_MAX_SPEED) {
                    $warningSegments[] = $way;
                } else {
                    $nudgeSegments[] = $way;
                }
            }
        }

        $alerts = [];

        if ([] !== $criticalSegments) {
            $first = $criticalSegments[0];
            $totalLength = array_sum(array_column($criticalSegments, 'length'));
            $lat = $first['lat'] ?? $stage->startPoint->lat;
            $lon = $first['lon'] ?? $stage->startPoint->lon;
            $alerts[] = new Alert(
                code: AlertCode::TRAFFIC_MAIN_ROAD,
                type: AlertType::CRITICAL,
                messageKey: 'alert.traffic.critical',
                parameters: ['%count%' => \count($criticalSegments), '%length%' => $totalLength],
                parameterFormats: ['%length%' => AlertParameterFormat::DISTANCE->value],
                lat: $lat,
                lon: $lon,
                action: new AlertAction(
                    kind: AlertActionKind::NAVIGATE,
                    labelKey: 'alert.traffic.action',
                    payload: ['lat' => $lat, 'lon' => $lon, 'segments' => $this->collectSegments($criticalSegments)],
                ),
            );
        }

        if ([] !== $warningSegments) {
            $first = $warningSegments[0];
            $totalLength = array_sum(array_column($warningSegments, 'length'));
            $lat = $first['lat'] ?? $stage->startPoint->lat;
            $lon = $first['lon'] ?? $stage->startPoint->lon;
            $alerts[] = new Alert(
                code: AlertCode::TRAFFIC_SECONDARY_ROAD_FAST,
                type: AlertType::WARNING,
                messageKey: 'alert.traffic.warning',
                parameters: ['%count%' => \count($warningSegments), '%length%' => $totalLength],
                parameterFormats: ['%length%' => AlertParameterFormat::DISTANCE->value],
                lat: $lat,
                lon: $lon,
                action: new AlertAction(
                    kind: AlertActionKind::NAVIGATE,
                    labelKey: 'alert.traffic.action',
                    payload: ['lat' => $lat, 'lon' => $lon, 'segments' => $this->collectSegments($warningSegments)],
                ),
            );
        }

        if ([] !== $nudgeSegments) {
            $first = $nudgeSegments[0];
            $totalLength = array_sum(array_column($nudgeSegments, 'length'));
            $speeds = array_filter(array_map(
                fn (array $w): ?int => $this->parseMaxspeed($w['maxspeed'] ?? ''),
                $nudgeSegments,
            ));
            $maxspeed = [] !== $speeds ? max($speeds) : self::NUDGE_MAX_SPEED;
            $lat = $first['lat'] ?? $stage->startPoint->lat;
            $lon = $first['lon'] ?? $stage->startPoint->lon;
            $alerts[] = new Alert(
                code: AlertCode::TRAFFIC_SECONDARY_ROAD_SLOW,
                type: AlertType::NUDGE,
                messageKey: 'alert.traffic.nudge',
                parameters: ['%count%' => \count($nudgeSegments), '%maxspeed%' => $maxspeed, '%length%' => $totalLength],
                parameterFormats: ['%length%' => AlertParameterFormat::DISTANCE->value],
                lat: $lat,
                lon: $lon,
                action: new AlertAction(
                    kind: AlertActionKind::NAVIGATE,
                    labelKey: 'alert.traffic.action',
                    payload: ['lat' => $lat, 'lon' => $lon, 'segments' => $this->collectSegments($nudgeSegments)],
                ),
            );
        }

        return $alerts;
    }

    /**
     * Flattens the clipped geometry of the ways in one severity bucket into a
     * single list of `[lat, lon]` polylines — the highlight payload the internal
     * map draws for the alert (issue #982).
     *
     * @param list<array{geometry?: list<list<array{0: float, 1: float}>>, ...}> $ways
     *
     * @return list<list<array{0: float, 1: float}>>
     */
    private function collectSegments(array $ways): array
    {
        $segments = [];
        foreach ($ways as $way) {
            foreach ($way['geometry'] ?? [] as $polyline) {
                $segments[] = $polyline;
            }
        }

        return $segments;
    }

    /**
     * @param array{highway?: string, cycleway?: string, 'cycleway:right'?: string, 'cycleway:left'?: string, 'cycleway:both'?: string, bicycle?: string, maxspeed?: string, length?: float, lat?: float, lon?: float, geometry?: list<list<array{0: float, 1: float}>>} $way
     */
    private function hasCycleInfrastructure(array $way): bool
    {
        if ('' !== ($way['cycleway'] ?? '')) {
            return true;
        }

        if ('' !== ($way['cycleway:right'] ?? '')) {
            return true;
        }

        if ('' !== ($way['cycleway:left'] ?? '')) {
            return true;
        }

        if ('' !== ($way['cycleway:both'] ?? '')) {
            return true;
        }

        return \in_array($way['bicycle'] ?? '', ['designated', 'use_sidepath'], true);
    }

    private function parseMaxspeed(string $maxspeed): ?int
    {
        if ('' === $maxspeed) {
            return null;
        }

        // Format: "50" or "50 km/h"
        if (preg_match('/^(\d+)/', $maxspeed, $matches)) {
            return (int) $matches[1];
        }

        // Format: "FR:50" (country code prefix)
        if (preg_match('/^[A-Z]{2}:(\d+)$/', $maxspeed, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    public static function getPriority(): int
    {
        return 20;
    }
}
