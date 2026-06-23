<?php

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\Llm\LlmResponseParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LlmResponseParserTest extends TestCase
{
    private LlmResponseParser $parser;

    #[\Override]
    protected function setUp(): void
    {
        $this->parser = new LlmResponseParser();
    }

    #[Test]
    public function extractsContentFromChatShape(): void
    {
        $this->assertSame('x', $this->parser->extractText(['message' => ['role' => 'assistant', 'content' => 'x']]));
    }

    #[Test]
    public function extractsTextFromGenerateShape(): void
    {
        $this->assertSame('y', $this->parser->extractText(['response' => 'y', 'done' => true]));
    }

    #[Test]
    public function fallsBackToResponseWhenContentNotString(): void
    {
        $this->assertSame('y', $this->parser->extractText(['message' => ['content' => ['nested']], 'response' => 'y']));
    }

    #[Test]
    public function returnsNullWhenContentNotStringAndNoResponse(): void
    {
        $this->assertNull($this->parser->extractText(['message' => ['content' => ['nested']]]));
    }

    #[Test]
    public function returnsNullForEmptyResponse(): void
    {
        $this->assertNull($this->parser->extractText([]));
    }
}
