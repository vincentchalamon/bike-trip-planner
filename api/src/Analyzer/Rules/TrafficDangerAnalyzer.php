<?php

declare(strict_types=1);

namespace App\Analyzer\Rules;

use App\Analyzer\StageAnalyzerInterface;
use App\ApiResource\Model\Alert;
use App\ApiResource\Stage;
use App\Enum\AlertType;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class TrafficDangerAnalyzer implements StageAnalyzerInterface
{
    /** @var list<string> */
    private const array CRITICAL_HIGHWAYS = ['primary', 'trunk'];

    /** @var list<string> */
    private const array WARNING_HIGHWAYS = ['secondary'];

    private const int MIN_SEGMENT_LENGTH = 500;

    private const int NUDGE_MAX_SPEED = 50;

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function analyze(Stage $stage, array $context = []): array
    {
        /** @var list<array{highway?: string, cycleway?: string, 'cycleway:right'?: string, 'cycleway:left'?: string, 'cycleway:both'?: string, bicycle?: string, maxspeed?: string, length?: float, lat?: float, lon?: float}> $osmWays */
        $osmWays = $context['osmWays'] ?? [];

        /** @var string $locale */
        $locale = $context['locale'] ?? 'en';

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
                $maxspeed = $this->parseMaxspeed($way['maxspeed'] ?? '');
                if (null !== $maxspeed && $maxspeed <= self::NUDGE_MAX_SPEED) {
                    $nudgeSegments[] = $way;
                } else {
                    $warningSegments[] = $way;
                }
            }
        }

        $alerts = [];

        if ([] !== $criticalSegments) {
            $first = $criticalSegments[0];
            $totalLength = (int) array_sum(array_column($criticalSegments, 'length'));
            $alerts[] = new Alert(
                type: AlertType::CRITICAL,
                message: $this->translator->trans(
                    'alert.traffic.critical',
                    ['%count%' => \count($criticalSegments), '%length%' => $totalLength],
                    'alerts',
                    $locale,
                ),
                lat: $first['lat'] ?? $stage->startPoint->lat,
                lon: $first['lon'] ?? $stage->startPoint->lon,
            );
        }

        if ([] !== $warningSegments) {
            $first = $warningSegments[0];
            $totalLength = (int) array_sum(array_column($warningSegments, 'length'));
            $alerts[] = new Alert(
                type: AlertType::WARNING,
                message: $this->translator->trans(
                    'alert.traffic.warning',
                    ['%count%' => \count($warningSegments), '%length%' => $totalLength],
                    'alerts',
                    $locale,
                ),
                lat: $first['lat'] ?? $stage->startPoint->lat,
                lon: $first['lon'] ?? $stage->startPoint->lon,
            );
        }

        if ([] !== $nudgeSegments) {
            $first = $nudgeSegments[0];
            $totalLength = (int) array_sum(array_column($nudgeSegments, 'length'));
            $speeds = array_filter(array_map(
                fn (array $w): ?int => $this->parseMaxspeed($w['maxspeed'] ?? ''),
                $nudgeSegments,
            ));
            $maxspeed = [] !== $speeds ? max($speeds) : self::NUDGE_MAX_SPEED;
            $alerts[] = new Alert(
                type: AlertType::NUDGE,
                message: $this->translator->trans(
                    'alert.traffic.nudge',
                    ['%count%' => \count($nudgeSegments), '%maxspeed%' => $maxspeed, '%length%' => $totalLength],
                    'alerts',
                    $locale,
                ),
                lat: $first['lat'] ?? $stage->startPoint->lat,
                lon: $first['lon'] ?? $stage->startPoint->lon,
            );
        }

        return $alerts;
    }

    /**
     * @param array{highway?: string, cycleway?: string, 'cycleway:right'?: string, 'cycleway:left'?: string, 'cycleway:both'?: string, bicycle?: string, maxspeed?: string, length?: float, lat?: float, lon?: float} $way
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
