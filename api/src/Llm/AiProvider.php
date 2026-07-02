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

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $provider): string => $provider->value, self::cases());
    }

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
            self::ANTHROPIC => 'claude-haiku-4-5-20251001',
            self::GEMINI => 'gemini-2.5-flash-lite',
            self::OPENAI => 'gpt-4o-mini',
        };
    }

    /**
     * Deeper model for async stage / trip analysis and itinerary generation.
     */
    public function analysisModel(): string
    {
        return match ($this) {
            self::ANTHROPIC => 'claude-sonnet-4-6',
            self::GEMINI => 'gemini-2.5-flash-lite',
            self::OPENAI => 'gpt-4o-mini',
        };
    }
}
