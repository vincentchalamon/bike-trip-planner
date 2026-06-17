<?php

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\Llm\AiProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiProviderTest extends TestCase
{
    #[Test]
    public function exposesACatalogValidModelPerRoleAndProvider(): void
    {
        // Models must exist in each bridge's ModelCatalog (the router rejects unknown names).
        self::assertSame('claude-3-5-haiku-latest', AiProvider::ANTHROPIC->chatModel());
        self::assertSame('claude-3-7-sonnet-latest', AiProvider::ANTHROPIC->analysisModel());
        self::assertSame('gpt-4o-mini', AiProvider::OPENAI->chatModel());
        self::assertSame('gpt-4o-mini', AiProvider::OPENAI->analysisModel());
        self::assertSame('gemini-2.5-flash', AiProvider::GEMINI->chatModel());
        self::assertSame('gemini-2.5-flash', AiProvider::GEMINI->analysisModel());
    }

    #[Test]
    public function mapsToAndFromTheStoredString(): void
    {
        self::assertSame('anthropic', AiProvider::ANTHROPIC->value);
        self::assertSame(AiProvider::OPENAI, AiProvider::from('openai'));
        self::assertNull(AiProvider::tryFrom('ollama'));
    }

    #[Test]
    public function hasAHumanLabel(): void
    {
        self::assertStringContainsString('Claude', AiProvider::ANTHROPIC->label());
        self::assertStringContainsString('Gemini', AiProvider::GEMINI->label());
        self::assertSame('OpenAI', AiProvider::OPENAI->label());
    }
}
