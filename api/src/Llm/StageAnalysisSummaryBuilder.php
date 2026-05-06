<?php

declare(strict_types=1);

namespace App\Llm;

use App\ApiResource\Model\Alert;
use App\ApiResource\Stage;
use App\Enum\AlertType;

/**
 * Builds the compact JSON summary fed to the LLaMA 8B pass-1 stage analysis prompt.
 *
 * The summary is intentionally concise (~200-300 tokens) so that the full prompt
 * (system prompt + summary + completion) stays well under 4K tokens, the optimal
 * window for LLaMA 8B inference on a CPU.
 *
 * The builder is stateless and pure — no I/O — so it can be unit-tested without
 * any Doctrine or Mercure dependency.
 */
final readonly class StageAnalysisSummaryBuilder
{
    /**
     * Soft cap on the number of alerts surfaced to the LLM.
     *
     * If the stage has more alerts than this, only the most severe ones (CRITICAL > WARNING > NUDGE)
     * are kept to stay below the prompt-size budget.
     */
    public const int MAX_ALERTS = 8;

    /**
     * Soft cap on resupply / accommodation / POI listings — same rationale.
     */
    public const int MAX_LIST_ITEMS = 6;

    /**
     * Builds the structured summary for a single stage.
     *
     * @return array<string, mixed> structure suitable for `json_encode()` (no nested objects)
     */
    public function build(Stage $stage): array
    {
        $summary = [
            'stage_number' => $stage->dayNumber,
            'distance_km' => round($stage->distance, 1),
            'elevation_gain_m' => (int) round($stage->elevation),
            'elevation_loss_m' => (int) round($stage->elevationLoss),
            'is_rest_day' => $stage->isRestDay,
        ];

        if (null !== $stage->label && '' !== $stage->label) {
            $summary['label'] = $stage->label;
        }

        $weather = $this->buildWeather($stage);
        if ([] !== $weather) {
            $summary['weather'] = $weather;
        }

        $alerts = $this->buildAlerts($stage);
        if ([] !== $alerts) {
            $summary['alerts'] = $alerts;
        }

        $waterPoints = $this->countWaterPoints($stage);
        if ($waterPoints > 0) {
            $summary['water_points'] = $waterPoints;
        }

        $resupply = $this->buildResupply($stage);
        if ([] !== $resupply) {
            $summary['resupply'] = $resupply;
        }

        $accommodations = $this->buildAccommodations($stage);
        if ([] !== $accommodations) {
            $summary['accommodations'] = $accommodations;
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildWeather(Stage $stage): array
    {
        $weather = $stage->weather;
        if (null === $weather) {
            return [];
        }

        return [
            'temp_min_c' => round($weather->tempMin, 1),
            'temp_max_c' => round($weather->tempMax, 1),
            'wind_kmh' => round($weather->windSpeed, 1),
            'wind_dir' => $weather->windDirection,
            'relative_wind' => $weather->relativeWindDirection,
            'precip_probability' => $weather->precipitationProbability,
            'comfort_index' => $weather->comfortIndex,
            'description' => $weather->description,
        ];
    }

    /**
     * Selects the most relevant alerts for the prompt.
     *
     * Alerts are sorted by severity (CRITICAL > WARNING > NUDGE) and the top N are kept.
     *
     * @return list<array{type: string, message: string}>
     */
    private function buildAlerts(Stage $stage): array
    {
        if ([] === $stage->alerts) {
            return [];
        }

        $alerts = $stage->alerts;

        usort(
            $alerts,
            static fn (Alert $a, Alert $b): int => self::severityRank($b->type) <=> self::severityRank($a->type),
        );

        $selected = \array_slice($alerts, 0, self::MAX_ALERTS);

        $out = [];
        foreach ($selected as $alert) {
            $out[] = [
                'type' => $alert->type->value,
                'message' => $alert->message,
            ];
        }

        return $out;
    }

    private static function severityRank(AlertType $type): int
    {
        return match ($type) {
            AlertType::CRITICAL => 3,
            AlertType::WARNING => 2,
            AlertType::NUDGE => 1,
        };
    }

    private function countWaterPoints(Stage $stage): int
    {
        $count = 0;
        foreach ($stage->pois as $poi) {
            if ('drinking_water' === $poi->category || 'water' === $poi->category) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @return list<array{name: string, type: string}>
     */
    private function buildResupply(Stage $stage): array
    {
        $relevant = ['supermarket', 'bakery', 'convenience', 'food', 'shop', 'cafe', 'restaurant'];
        $items = [];

        foreach ($stage->pois as $poi) {
            if (\in_array($poi->category, $relevant, true)) {
                $items[] = ['name' => $poi->name, 'type' => $poi->category];
                if (\count($items) >= self::MAX_LIST_ITEMS) {
                    break;
                }
            }
        }

        return $items;
    }

    /**
     * @return list<array{name: string, type: string}>
     */
    private function buildAccommodations(Stage $stage): array
    {
        $items = [];

        // Surface the selected accommodation first if any.
        if (null !== $stage->selectedAccommodation) {
            $items[] = [
                'name' => $stage->selectedAccommodation->name,
                'type' => $stage->selectedAccommodation->type,
            ];
        }

        foreach ($stage->accommodations as $accommodation) {
            if (
                null !== $stage->selectedAccommodation
                && $accommodation->name === $stage->selectedAccommodation->name
                && $accommodation->lat === $stage->selectedAccommodation->lat
                && $accommodation->lon === $stage->selectedAccommodation->lon
            ) {
                continue;
            }

            $items[] = ['name' => $accommodation->name, 'type' => $accommodation->type];
            if (\count($items) >= self::MAX_LIST_ITEMS) {
                break;
            }
        }

        return $items;
    }
}
