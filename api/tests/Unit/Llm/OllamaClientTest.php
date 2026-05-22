<?php

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\Llm\Exception\OllamaUnavailableException;
use App\Llm\OllamaClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\Ollama\Factory as OllamaPlatformFactory;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OllamaClientTest extends TestCase
{
    private const string BASE_URI = 'http://ollama:11434';

    // -------------------------------------------------------------------------
    // isEnabled() / disabled flag
    // -------------------------------------------------------------------------

    #[Test]
    public function isEnabledReflectsTheConfigurationFlag(): void
    {
        $this->assertTrue($this->makeClient(enabled: true)->isEnabled());
        $this->assertFalse($this->makeClient(enabled: false)->isEnabled());
    }

    #[Test]
    public function generateReturnsNullAndDoesNotCallHttpWhenDisabled(): void
    {
        $platform = $this->createMock(PlatformInterface::class);
        $platform->expects($this->never())->method('invoke');

        $client = new OllamaClient($platform, new NullLogger(), enabled: false);

        $this->assertNull($client->generate('llama3.2:3b', 'hello'));
    }

    #[Test]
    public function chatReturnsNullAndDoesNotCallHttpWhenDisabled(): void
    {
        $platform = $this->createMock(PlatformInterface::class);
        $platform->expects($this->never())->method('invoke');

        $client = new OllamaClient($platform, new NullLogger(), enabled: false);

        $this->assertNull($client->chat('llama3.2:3b', [['role' => 'user', 'content' => 'hi']]));
    }

    // -------------------------------------------------------------------------
    // generate() — happy path
    // -------------------------------------------------------------------------

    #[Test]
    public function generateSendsExpectedPayloadAndReturnsLegacyShape(): void
    {
        $captured = [];
        $platform = $this->makePlatform($captured, '{"ok":true}');

        $client = new OllamaClient($platform, new NullLogger(), enabled: true);

        $result = $client->generate(
            model: 'llama3.2:3b',
            prompt: 'Summarize this stage.',
            systemPrompt: 'You are a concise assistant.',
            options: ['temperature' => 0.2, 'num_ctx' => 4096, 'num_predict' => 100],
        );

        $this->assertSame(['response' => '{"ok":true}'], $result);

        $chatBody = $this->lastChatBody($captured);
        $this->assertSame('llama3.2:3b', $chatBody['model']);
        $this->assertSame([
            ['role' => 'system', 'content' => 'You are a concise assistant.'],
            ['role' => 'user', 'content' => 'Summarize this stage.'],
        ], $chatBody['messages']);
        $this->assertFalse($chatBody['stream']);
        $this->assertSame('json', $chatBody['format']);
        $options = $chatBody['options'];
        \assert(\is_array($options));
        $this->assertSame(4096, $options['num_ctx']);
        $this->assertSame(0.2, $options['temperature']);
        // Guards token-count caps (50–600 across callers): if the bridge ever stops forwarding
        // unknown nested keys, inference would become unbounded on every production call.
        $this->assertSame(100, $options['num_predict']);
    }

    #[Test]
    public function generateAppliesDefaultOptionsWhenNoneProvided(): void
    {
        $captured = [];
        $platform = $this->makePlatform($captured, '{}');

        $client = new OllamaClient($platform, new NullLogger(), enabled: true);
        $client->generate('llama3.1:8b', 'analyze');

        $chatBody = $this->lastChatBody($captured);
        $options = $chatBody['options'];
        \assert(\is_array($options));
        $this->assertSame(OllamaClient::DEFAULT_NUM_CTX, $options['num_ctx']);
        $this->assertSame(OllamaClient::DEFAULT_TEMPERATURE, $options['temperature']);
        $this->assertSame('json', $chatBody['format']);
        $this->assertSame([['role' => 'user', 'content' => 'analyze']], $chatBody['messages']);
    }

    // -------------------------------------------------------------------------
    // chat() — happy path + system prompt prepending
    // -------------------------------------------------------------------------

    #[Test]
    public function chatPrependsSystemPromptAndPreservesRoles(): void
    {
        $captured = [];
        $platform = $this->makePlatform($captured, 'ack');

        $client = new OllamaClient($platform, new NullLogger(), enabled: true);

        $result = $client->chat(
            model: 'llama3.2:3b',
            messages: [
                ['role' => 'user', 'content' => 'Hi'],
                ['role' => 'assistant', 'content' => 'Hello!'],
                ['role' => 'user', 'content' => 'How are you?'],
            ],
            systemPrompt: 'You are helpful.',
        );

        $this->assertSame(['message' => ['role' => 'assistant', 'content' => 'ack']], $result);

        $chatBody = $this->lastChatBody($captured);
        $this->assertSame([
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'user', 'content' => 'Hi'],
            ['role' => 'assistant', 'content' => 'Hello!'],
            ['role' => 'user', 'content' => 'How are you?'],
        ], $chatBody['messages']);
    }

    #[Test]
    public function chatDoesNotPrependSystemPromptWhenNotProvided(): void
    {
        $captured = [];
        $platform = $this->makePlatform($captured, '{}');

        $client = new OllamaClient($platform, new NullLogger(), enabled: true);
        $client->chat('llama3.2:3b', [['role' => 'user', 'content' => 'Hi']]);

        $chatBody = $this->lastChatBody($captured);
        $this->assertSame([['role' => 'user', 'content' => 'Hi']], $chatBody['messages']);
    }

    // -------------------------------------------------------------------------
    // Error handling — transport / unreachable
    // -------------------------------------------------------------------------

    #[Test]
    public function generateThrowsOllamaUnavailableExceptionOnTransportError(): void
    {
        $platform = OllamaPlatformFactory::createPlatform(
            self::BASE_URI,
            null,
            new MockHttpClient(static fn (): MockResponse => throw new TransportException('Connection refused'), self::BASE_URI),
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('Ollama request failed'));

        $client = new OllamaClient($platform, $logger, enabled: true);

        $this->expectException(OllamaUnavailableException::class);
        $this->expectExceptionMessage('llama3.2:3b');

        $client->generate('llama3.2:3b', 'prompt');
    }

    #[Test]
    public function generateThrowsOllamaUnavailableExceptionOnHttp5xx(): void
    {
        $platform = OllamaPlatformFactory::createPlatform(
            self::BASE_URI,
            null,
            new MockHttpClient(static fn (): MockResponse => new MockResponse('upstream down', ['http_code' => 503]), self::BASE_URI),
        );

        $client = new OllamaClient($platform, new NullLogger(), enabled: true);

        $this->expectException(OllamaUnavailableException::class);

        $client->generate('llama3.2:3b', 'prompt');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<int, array{path: string, body: array<string, mixed>}> $captured
     */
    private function makePlatform(array &$captured, string $responseContent): PlatformInterface
    {
        $callback = function (string $method, string $url, array $options) use (&$captured, $responseContent): MockResponse {
            $path = parse_url($url, \PHP_URL_PATH) ?: $url;
            $body = isset($options['body']) && \is_string($options['body'])
                ? json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR)
                : [];
            \assert(\is_array($body));
            /* @var array<string, mixed> $body */
            $captured[] = ['path' => $path, 'body' => $body];

            return match (true) {
                str_ends_with($path, '/api/show') => new MockResponse(
                    json_encode(['capabilities' => ['completion', 'tools']], \JSON_THROW_ON_ERROR),
                    ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
                ),
                str_ends_with($path, '/api/chat') => new MockResponse(
                    json_encode([
                        'model' => $body['model'] ?? 'llama3.2:3b',
                        'message' => ['role' => 'assistant', 'content' => $responseContent],
                        'done' => true,
                    ], \JSON_THROW_ON_ERROR),
                    ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
                ),
                default => new MockResponse('{}', ['http_code' => 404]),
            };
        };

        return OllamaPlatformFactory::createPlatform(
            self::BASE_URI,
            null,
            new MockHttpClient($callback, self::BASE_URI),
        );
    }

    /**
     * @param array<int, array{path: string, body: array<string, mixed>}> $captured
     *
     * @return array<string, mixed>
     */
    private function lastChatBody(array $captured): array
    {
        foreach (array_reverse($captured) as $entry) {
            if (str_ends_with($entry['path'], '/api/chat')) {
                return $entry['body'];
            }
        }

        self::fail('No /api/chat call was captured.');
    }

    private function makeClient(bool $enabled): OllamaClient
    {
        return new OllamaClient(
            $this->createStub(PlatformInterface::class),
            new NullLogger(),
            $enabled,
        );
    }
}
