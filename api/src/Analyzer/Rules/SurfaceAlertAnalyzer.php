<?php

declare(strict_types=1);

namespace App\Analyzer\Rules;

use App\Analyzer\StageAnalyzerInterface;
use App\ApiResource\Model\Alert;
use App\ApiResource\Model\AlertAction;
use App\ApiResource\Model\AlertActionKind;
use App\ApiResource\Stage;
use App\Enum\AlertType;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class SurfaceAlertAnalyzer implements StageAnalyzerInterface
{
    private const int UNPAVED_THRESHOLD_METERS = 500;

    /** @var list<string> */
    private const array UNPAVED_SURFACES = [
        'unpaved', 'gravel', 'dirt', 'ground', 'grass', 'sand',
        'mud', 'compacted', 'fine_gravel', 'pebblestone',
    ];

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function analyze(Stage $stage, array $context = []): array
    {
        /** @var list<array{surface?: string, length?: float}> $osmWays */
        $osmWays = $context['osmWays'] ?? [];

        if ([] === $osmWays) {
            return [];
        }

        /** @var string $locale */
        $locale = $context['locale'] ?? 'en';

        return $this->detectUnpavedSections($osmWays, $stage, $locale);
    }

    public static function getPriority(): int
    {
        return 20;
    }

    /**
     * @param list<array{surface?: string, length?: float}> $osmWays
     *
     * @return list<Alert>
     */
    private function detectUnpavedSections(array $osmWays, Stage $stage, string $locale): array
    {
        $unpavedLength = 0.0;
        $surfaces = [];

        foreach ($osmWays as $way) {
            $surface = $way['surface'] ?? '';
            if (\in_array($surface, self::UNPAVED_SURFACES, true)) {
                $unpavedLength += $way['length'] ?? 0.0;
                $surfaces[$surface] = true;
            }
        }

        if ($unpavedLength < self::UNPAVED_THRESHOLD_METERS) {
            return [];
        }

        return [new Alert(
            type: AlertType::WARNING,
            message: $this->translator->trans(
                'alert.surface.warning',
                [
                    '%length%' => (int) $unpavedLength,
                    '%surface%' => implode(', ', array_keys($surfaces)),
                ],
                'alerts',
                $locale,
            ),
            lat: $stage->startPoint->lat,
            lon: $stage->startPoint->lon,
            action: new AlertAction(
                kind: AlertActionKind::NAVIGATE,
                label: $this->translator->trans('alert.surface.action', [], 'alerts', $locale),
                payload: ['lat' => $stage->startPoint->lat, 'lon' => $stage->startPoint->lon],
            ),
        )];
    }
}
