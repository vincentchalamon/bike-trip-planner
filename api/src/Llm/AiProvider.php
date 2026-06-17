<?php

declare(strict_types=1);

namespace App\Llm;

/**
 * Cloud AI providers a user can bring their own API token for (ADR-042). Each
 * provider maps to a `symfony/ai-platform` bridge and a sensible default model
 * per role. Defaults are *cheap-but-capable* and chosen from each bridge's
 * ModelCatalog (the model router rejects unknown names); they are intentionally
 * not user-selectable in v1 and can later be overridden by configuration.
 */
enum AiProvider: string
{
    case ANTHROPIC = 'anthropic';
    case GEMINI = 'gemini';
    case OPENAI = 'openai';

    public function label(): string
    {
        return match ($this) {
            self::ANTHROPIC => 'Anthropic (Claude)',
            self::GEMINI => 'Google (Gemini)',
            self::OPENAI => 'OpenAI',
        };
    }

    /**
     * Low-latency model for dialogue / chat / in-ride.
     */
    public function chatModel(): string
    {
        return match ($this) {
            self::ANTHROPIC => 'claude-3-5-haiku-latest',
            self::GEMINI => 'gemini-2.5-flash',
            self::OPENAI => 'gpt-4o-mini',
        };
    }

    /**
     * Deeper model for async stage / trip analysis and itinerary generation.
     */
    public function analysisModel(): string
    {
        return match ($this) {
            self::ANTHROPIC => 'claude-3-7-sonnet-latest',
            self::GEMINI => 'gemini-2.5-flash',
            self::OPENAI => 'gpt-4o-mini',
        };
    }
}
