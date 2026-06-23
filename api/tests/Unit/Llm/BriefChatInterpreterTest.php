<?php

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\Llm\BriefChatInterpreter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BriefChatInterpreterTest extends TestCase
{
    private BriefChatInterpreter $interpreter;

    #[\Override]
    protected function setUp(): void
    {
        $this->interpreter = new BriefChatInterpreter();
    }

    #[Test]
    public function parsesWellFormedEnvelope(): void
    {
        $reply = $this->interpreter->interpret(
            json_encode([
                'reply' => 'Boucle gravel de 2 jours au départ de Lille, on peut lancer.',
                'readyToGenerate' => true,
                'collected' => ['start' => 'Lille', 'loop' => true, 'durationDays' => 2, 'profile' => 'gravel'],
            ], \JSON_THROW_ON_ERROR),
        );

        $this->assertStringContainsString('Lille', $reply->reply);
        $this->assertTrue($reply->readyToGenerate);
        $this->assertSame(['start' => 'Lille', 'loop' => true, 'durationDays' => 2, 'profile' => 'gravel'], $reply->collected);
    }

    #[Test]
    public function defaultsReadyToGenerateToFalseWhenAbsent(): void
    {
        $reply = $this->interpreter->interpret(
            json_encode([
                'reply' => 'Tu pars d où ?',
                'collected' => [],
            ], \JSON_THROW_ON_ERROR),
        );

        $this->assertFalse($reply->readyToGenerate);
        $this->assertSame([], $reply->collected);
    }

    #[Test]
    public function toleratesMarkdownCodeFence(): void
    {
        $payload = "```json\n".json_encode([
            'reply' => 'OK.',
            'readyToGenerate' => false,
            'collected' => ['start' => 'Tours'],
        ], \JSON_THROW_ON_ERROR)."\n```";

        $reply = $this->interpreter->interpret($payload);

        $this->assertSame('OK.', $reply->reply);
        $this->assertSame(['start' => 'Tours'], $reply->collected);
    }

    #[Test]
    public function extractsJsonEmbeddedInProse(): void
    {
        $payload = 'Voici ma réponse : '.json_encode([
            'reply' => 'Bonjour.',
            'readyToGenerate' => false,
            'collected' => [],
        ], \JSON_THROW_ON_ERROR).' Fin.';

        $reply = $this->interpreter->interpret($payload);

        $this->assertSame('Bonjour.', $reply->reply);
        $this->assertFalse($reply->readyToGenerate);
    }

    #[Test]
    public function fallsBackToRawTextWhenNotJson(): void
    {
        $reply = $this->interpreter->interpret('Je ne suis pas du JSON, juste du texte.');

        $this->assertSame('Je ne suis pas du JSON, juste du texte.', $reply->reply);
        $this->assertFalse($reply->readyToGenerate);
        $this->assertSame([], $reply->collected);
    }

    #[Test]
    public function fallsBackToRawTextOnEmptyContent(): void
    {
        $reply = $this->interpreter->interpret('');

        $this->assertSame('', $reply->reply);
        $this->assertFalse($reply->readyToGenerate);
        $this->assertSame([], $reply->collected);
    }

    #[Test]
    public function usesRawTextWhenReplyFieldIsMissing(): void
    {
        $reply = $this->interpreter->interpret(
            json_encode(['readyToGenerate' => true, 'collected' => ['start' => 'Lille']], \JSON_THROW_ON_ERROR),
        );

        // No usable `reply` field → degrade to the raw text, but still keep the
        // structured fields the model did provide.
        $this->assertNotSame('', $reply->reply);
        $this->assertTrue($reply->readyToGenerate);
        $this->assertSame(['start' => 'Lille'], $reply->collected);
    }

    #[Test]
    public function dropsNonScalarCollectedEntries(): void
    {
        $reply = $this->interpreter->interpret(
            json_encode([
                'reply' => 'OK.',
                'readyToGenerate' => false,
                'collected' => ['start' => 'Lille', 'waypoints' => ['a', 'b'], 'end' => null],
            ], \JSON_THROW_ON_ERROR),
        );

        // Nested arrays are dropped; scalars and explicit nulls are kept.
        $this->assertSame(['start' => 'Lille', 'end' => null], $reply->collected);
    }

    #[Test]
    public function readyToGenerateIsFalseUnlessStrictlyTrue(): void
    {
        $reply = $this->interpreter->interpret(
            json_encode([
                'reply' => 'OK.',
                'readyToGenerate' => 'yes',
                'collected' => [],
            ], \JSON_THROW_ON_ERROR),
        );

        $this->assertFalse($reply->readyToGenerate);
    }
}
