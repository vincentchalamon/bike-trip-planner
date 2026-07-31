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
    private const int ROUGH_THRESHOLD_METERS = 500;

    /** @var list<string> */
    private const array UNPAVED_SURFACES = [
        'unpaved', 'gravel', 'dirt', 'ground', 'grass', 'sand',
        'mud', 'compacted', 'fine_gravel', 'pebblestone',
        'earth', 'clay', 'rock', 'stone', 'woodchips', 'wood', 'metal',
    ];

    /**
     * Paved, but rough enough to matter on a loaded bike. Kept apart from the
     * unpaved values so the alert wording stays truthful (cobbles *are* paved).
     *
     * @var list<string>
     */
    private const array ROUGH_PAVED_SURFACES = [
        'sett', 'cobblestone', 'unhewn_cobblestone', 'paving_stones',
    ];

    /** @var list<string> */
    private const array ROUGH_SURFACES = [...self::UNPAVED_SURFACES, ...self::ROUGH_PAVED_SURFACES];

    /**
     * Secondary signals, used only when `surface` is absent: an unmaintained
     * track or a very poor smoothness is de facto not a road surface.
     *
     * @var list<string>
     */
    private const array UNPAVED_TRACKTYPES = ['grade3', 'grade4', 'grade5'];

    /** @var list<string> */
    private const array ROUGH_SMOOTHNESS = ['bad', 'very_bad', 'horrible', 'very_horrible', 'impassable'];

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function analyze(Stage $stage, array $context = []): array
    {
        /** @var list<array{surface?: string, tracktype?: string, smoothness?: string, length?: float}> $osmWays */
        $osmWays = $context['osmWays'] ?? [];

        if ([] === $osmWays) {
            return [];
        }

        /** @var string $locale */
        $locale = $context['locale'] ?? 'en';

        return $this->detectRoughSections($osmWays, $stage, $locale);
    }

    public static function getPriority(): int
    {
        return 20;
    }

    /**
     * @param list<array{surface?: string, tracktype?: string, smoothness?: string, length?: float}> $osmWays
     *
     * @return list<Alert>
     */
    private function detectRoughSections(array $osmWays, Stage $stage, string $locale): array
    {
        $roughLength = 0.0;
        $surfaces = [];

        foreach ($osmWays as $way) {
            $matched = $this->roughSurfacesOf($way);
            if ([] === $matched) {
                continue;
            }

            $roughLength += $way['length'] ?? 0.0;
            foreach ($matched as $surface) {
                $surfaces[$surface] = true;
            }
        }

        if ($roughLength < self::ROUGH_THRESHOLD_METERS) {
            return [];
        }

        return [new Alert(
            type: AlertType::WARNING,
            message: $this->translator->trans(
                'alert.surface.warning',
                [
                    '%length%' => (int) $roughLength,
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

    /**
     * The rough surface values carried by a way, empty when it rides smooth.
     *
     * OSM allows composite values (`surface=gravel;dirt`), so each component is
     * tested. `tracktype` / `smoothness` are only a fallback: an explicit
     * `surface` always wins, so `surface=asphalt` + `smoothness=bad` is smooth.
     *
     * @param array{surface?: string, tracktype?: string, smoothness?: string, length?: float} $way
     *
     * @return list<string>
     */
    private function roughSurfacesOf(array $way): array
    {
        $surface = trim($way['surface'] ?? '');

        if ('' !== $surface) {
            $components = array_map(trim(...), explode(';', strtolower($surface)));

            return array_values(array_filter(
                $components,
                static fn (string $component): bool => \in_array($component, self::ROUGH_SURFACES, true),
            ));
        }

        $tracktype = strtolower(trim($way['tracktype'] ?? ''));
        if (\in_array($tracktype, self::UNPAVED_TRACKTYPES, true)) {
            return ['tracktype='.$tracktype];
        }

        $smoothness = strtolower(trim($way['smoothness'] ?? ''));
        if (\in_array($smoothness, self::ROUGH_SMOOTHNESS, true)) {
            return ['smoothness='.$smoothness];
        }

        return [];
    }
}
