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
    private const array DANGEROUS_HIGHWAYS = ['primary', 'secondary'];

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

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

        /** @var string $locale */
        $locale = $context['locale'] ?? 'en';

        return [new Alert(
            type: AlertType::CRITICAL,
            message: $this->translator->trans(
                'alert.traffic.critical',
                ['%count%' => \count($dangerousSegments)],
                'alerts',
                $locale,
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
