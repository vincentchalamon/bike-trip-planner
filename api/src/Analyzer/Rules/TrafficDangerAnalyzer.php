<?php

declare(strict_types=1);

namespace App\Analyzer\Rules;

use App\Analyzer\StageAnalyzerInterface;
use App\ApiResource\Model\Alert;
use App\ApiResource\Stage;
use App\Enum\AlertType;

final readonly class TrafficDangerAnalyzer implements StageAnalyzerInterface
{
    /** @var list<string> */
    private const array DANGEROUS_HIGHWAYS = ['primary', 'secondary'];

    public function analyze(Stage $stage, array $context = []): array
    {
        /** @var list<array{highway?: string, cycleway?: string, lat?: float, lon?: float}> $osmWays */
        $osmWays = $context['osmWays'] ?? [];

        $dangerousSegments = [];

        foreach ($osmWays as $way) {
            $highway = $way['highway'] ?? '';
            $cycleway = $way['cycleway'] ?? '';
            $cyclewayRight = $way['cycleway:right'] ?? '';
            $cyclewayLeft = $way['cycleway:left'] ?? '';

            if (
                \in_array($highway, self::DANGEROUS_HIGHWAYS, true)
                && '' === $cycleway
                && '' === $cyclewayRight
                && '' === $cyclewayLeft
            ) {
                $dangerousSegments[] = $way;
            }
        }

        if ([] === $dangerousSegments) {
            return [];
        }

        $first = $dangerousSegments[0];

        return [new Alert(
            type: AlertType::CRITICAL,
            message: \sprintf(
                '%d segment(s) sur route principale (primary/secondary) sans piste cyclable détecté(s).',
                \count($dangerousSegments),
            ),
            lat: $first['lat'] ?? $stage->startPoint->lat,
            lon: $first['lon'] ?? $stage->startPoint->lon,
        )];
    }

    public static function getPriority(): int
    {
        return 20;
    }
}
