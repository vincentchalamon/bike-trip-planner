<?php

declare(strict_types=1);

namespace App\Llm\Dto;

/**
 * Result of the LLaMA 8B pass-2 trip overview synthesis (issue #302).
 *
 * The LLM returns a Markdown briefing with four sections (Vue d'ensemble, Charge
 * et fatigue cumulative, Patterns transversaux, Recommandations globales). The
 * handler extracts each section into this immutable DTO and serialises it as
 * JSONB on the trip table (`trip.ai_overview`).
 *
 * Shape mirrors {@see StageAiAnalysis} for symmetry, with two extra collections:
 * `patterns` (cross-stage observations) and `crossStageAlerts` (trip-level
 * warnings spanning multiple days).
 */
final readonly class TripAiOverview
{
    /**
     * @param string       $narrative        global narrative paragraph (Vue d'ensemble + Charge et fatigue cumulative, ~120 words)
     * @param list<string> $patterns         cross-stage patterns the rider should be aware of
     * @param list<string> $recommendations  trip-level actionable recommendations
     * @param list<string> $crossStageAlerts trip-level alerts spanning multiple stages (subset of patterns flagged as warnings)
     * @param string       $model            LLM model identifier used to produce the overview (e.g. "llama3.1:8b")
     * @param int          $promptVersion    identifies the system prompt revision (so consumers can detect staleness)
     * @param string       $generatedAt      RFC3339 timestamp when the overview was generated
     */
    public function __construct(
        public string $narrative,
        public array $patterns,
        public array $recommendations,
        public array $crossStageAlerts,
        public string $model,
        public int $promptVersion,
        public string $generatedAt,
    ) {
    }

    /**
     * Serialises the DTO to a JSONB-compatible array.
     *
     * @return array{narrative: string, patterns: list<string>, recommendations: list<string>, crossStageAlerts: list<string>, model: string, promptVersion: int, generatedAt: string}
     */
    public function toArray(): array
    {
        return [
            'narrative' => $this->narrative,
            'patterns' => $this->patterns,
            'recommendations' => $this->recommendations,
            'crossStageAlerts' => $this->crossStageAlerts,
            'model' => $this->model,
            'promptVersion' => $this->promptVersion,
            'generatedAt' => $this->generatedAt,
        ];
    }

    /**
     * @param array{narrative?: string, patterns?: list<string>, recommendations?: list<string>, crossStageAlerts?: list<string>, model?: string, promptVersion?: int, generatedAt?: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            narrative: $data['narrative'] ?? '',
            patterns: $data['patterns'] ?? [],
            recommendations: $data['recommendations'] ?? [],
            crossStageAlerts: $data['crossStageAlerts'] ?? [],
            model: $data['model'] ?? '',
            promptVersion: $data['promptVersion'] ?? 1,
            generatedAt: $data['generatedAt'] ?? '',
        );
    }
}
