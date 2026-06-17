<?php

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\Llm\Exception\AiUnavailableException;
use App\Llm\PlatformLlmClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Test\InMemoryPlatform;
use Symfony\Component\HttpClient\Exception\TransportException;

final class PlatformLlmClientTest extends TestCase
{
    #[Test]
    public function generateWrapsThePlatformTextResponse(): void
    {
        $client = new PlatformLlmClient(new InMemoryPlatform('{"narrative":"ok"}'));

        self::assertSame(['response' => '{"narrative":"ok"}'], $client->generate('claude-3-5-haiku-latest', 'hello'));
    }

    #[Test]
    public function chatMapsRolesAndWrapsTheAssistantMessage(): void
    {
        $captured = null;
        $client = new PlatformLlmClient(new InMemoryPlatform(static function (object $model, object $input) use (&$captured): string {
            $captured = $input;

            return 'pong';
        }));

        $result = $client->chat('gpt-4o-mini', [['role' => 'user', 'content' => 'ping']], 'you are a guide');

        self::assertSame(['message' => ['role' => 'assistant', 'content' => 'pong']], $result);
        self::assertNotNull($captured, 'the platform was invoked with a message bag');
    }

    #[Test]
    public function isEnabledIsAlwaysTrueForAConstructedClient(): void
    {
        self::assertTrue(new PlatformLlmClient(new InMemoryPlatform('x'))->isEnabled());
    }

    #[Test]
    public function rejectsAnEmptyModelName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PlatformLlmClient(new InMemoryPlatform('x'))->generate('', 'hello');
    }

    #[Test]
    public function wrapsTransportErrorsIntoAiUnavailableException(): void
    {
        $client = new PlatformLlmClient(new InMemoryPlatform(static function (): string {
            throw new TransportException('connection refused');
        }));

        $this->expectException(AiUnavailableException::class);
        $client->generate('claude-3-5-haiku-latest', 'hello');
    }
}
