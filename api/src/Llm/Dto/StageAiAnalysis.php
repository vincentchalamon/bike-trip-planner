<?php

declare(strict_types=1);

namespace App\Llm\Dto;

/**
 * Result of the LLaMA 8B pass-1 stage analysis (issue #301).
 *
 * The LLM returns a Markdown briefing with three sections (Synthèse, Insights,
 * Recommandations). To keep persistence schema-stable and avoid forcing the
 * model into a strict JSON envelope, the handler extracts the three sections
 * into this immutable DTO and serialises it as JSONB on the Stage entity.
 *
 * Stored as JSONB in the `stage.ai_analysis` column.
 */
final readonly class StageAiAnalysis
{
    /**
     * @param string       $narrative     short narrative paragraph (Synthèse section, ~80 words)
     * @param list<string> $insights      list of non-obvious facts the rider should know
     * @param list<string> $suggestions   list of actionable recommendations for the rider
     * @param string       $model         LLM model identifier used to produce the analysis (e.g. "llama3.1:8b")
     * @param int          $promptVersion identifies the system prompt revision (so consumers can detect staleness)
     * @param string       $generatedAt   RFC3339 timestamp when the analysis was generated
     */
    public function __construct(
        public string $narrative,
        public array $insights,
        public array $suggestions,
        public string $model,
        public int $promptVersion,
        public string $generatedAt,
    ) {
    }

    /**
     * Serialises the DTO to a JSONB-compatible array.
     *
     * @return array{narrative: string, insights: list<string>, suggestions: list<string>, model: string, promptVersion: int, generatedAt: string}
     */
    public function toArray(): array
    {
        return [
            'narrative' => $this->narrative,
            'insights' => $this->insights,
            'suggestions' => $this->suggestions,
            'model' => $this->model,
            'promptVersion' => $this->promptVersion,
            'generatedAt' => $this->generatedAt,
        ];
    }

    /**
     * @param array{narrative?: string, insights?: list<string>, suggestions?: list<string>, model?: string, promptVersion?: int, generatedAt?: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            narrative: $data['narrative'] ?? '',
            insights: $data['insights'] ?? [],
            suggestions: $data['suggestions'] ?? [],
            model: $data['model'] ?? '',
            promptVersion: $data['promptVersion'] ?? 1,
            generatedAt: $data['generatedAt'] ?? '',
        );
    }
}
