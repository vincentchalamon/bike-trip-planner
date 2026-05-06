<?php

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\Llm\Exception\OllamaUnavailableException;
use App\Llm\OllamaClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
        $httpClient = new MockHttpClient(static function (): MockResponse {
            self::fail('HTTP client must not be called when Ollama is disabled.');
        }, self::BASE_URI);

        $client = $this->makeClient(httpClient: $httpClient, enabled: false);

        $this->assertNull($client->generate('llama3.2:3b', 'hello'));
    }

    #[Test]
    public function chatReturnsNullAndDoesNotCallHttpWhenDisabled(): void
    {
        $httpClient = new MockHttpClient(static function (): MockResponse {
            self::fail('HTTP client must not be called when Ollama is disabled.');
        }, self::BASE_URI);

        $client = $this->makeClient(httpClient: $httpClient, enabled: false);

        $this->assertNull($client->chat('llama3.2:3b', [['role' => 'user', 'content' => 'hi']]));
    }

    // -------------------------------------------------------------------------
    // generate() — happy path
    // -------------------------------------------------------------------------

    #[Test]
    public function generateSendsExpectedPayloadAndReturnsParsedJson(): void
    {
        $apiResponse = ['model' => 'llama3.2:3b', 'response' => '{"ok":true}', 'done' => true];

        $captured = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured, $apiResponse): MockResponse {
            $captured = [
                'method' => $method,
                'url' => $url,
                'body' => $options['body'] ?? null,
            ];

            return new MockResponse(
                json_encode($apiResponse, \JSON_THROW_ON_ERROR),
                ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
            );
        }, self::BASE_URI);

        $client = $this->makeClient(httpClient: $httpClient, enabled: true);

        $result = $client->generate(
            model: 'llama3.2:3b',
            prompt: 'Summarize this stage.',
            systemPrompt: 'You are a concise assistant.',
            options: ['temperature' => 0.2, 'num_ctx' => 4096],
        );

        $this->assertSame($apiResponse, $result);
        $this->assertSame('POST', $captured['method']);
        $this->assertStringEndsWith('/api/generate', $captured['url']);

        $this->assertIsString($captured['body']);
        /** @var array<string, mixed> $payload */
        $payload = json_decode($captured['body'], true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame('llama3.2:3b', $payload['model']);
        $this->assertSame('Summarize this stage.', $payload['prompt']);
        $this->assertSame('You are a concise assistant.', $payload['system']);
        $this->assertFalse($payload['stream']);
        $this->assertSame('json', $payload['format']);
        $this->assertIsArray($payload['options']);
        $this->assertSame(4096, $payload['options']['num_ctx']);
        $this->assertSame(0.2, $payload['options']['temperature']);
    }

    #[Test]
    public function generateAppliesDefaultOptionsWhenNoneProvided(): void
    {
        $captured = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['body' => $options['body'] ?? null];

            return new MockResponse('{"response":"{}","done":true}', ['http_code' => 200]);
        }, self::BASE_URI);

        $client = $this->makeClient(httpClient: $httpClient, enabled: true);

        $client->generate('llama3.1:8b', 'analyze');

        $this->assertIsString($captured['body']);
        /** @var array<string, mixed> $payload */
        $payload = json_decode($captured['body'], true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload['options']);
        $this->assertSame(OllamaClient::DEFAULT_NUM_CTX, $payload['options']['num_ctx']);
        $this->assertSame(OllamaClient::DEFAULT_TEMPERATURE, $payload['options']['temperature']);
        $this->assertSame('json', $payload['format']);
        $this->assertArrayNotHasKey('system', $payload);
    }

    // -------------------------------------------------------------------------
    // chat() — happy path + system prompt prepending
    // -------------------------------------------------------------------------

    #[Test]
    public function chatPrependsSystemPromptToMessages(): void
    {
        $captured = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = [
                'url' => $url,
                'body' => $options['body'] ?? null,
            ];

            return new MockResponse('{"message":{"role":"assistant","content":"{}"},"done":true}', ['http_code' => 200]);
        }, self::BASE_URI);

        $client = $this->makeClient(httpClient: $httpClient, enabled: true);

        $messages = [['role' => 'user', 'content' => 'Hi']];

        $result = $client->chat(
            model: 'llama3.2:3b',
            messages: $messages,
            systemPrompt: 'You are helpful.',
        );

        $this->assertNotNull($result);
        $this->assertStringEndsWith('/api/chat', $captured['url']);

        $this->assertIsString($captured['body']);
        /** @var array<string, mixed> $payload */
        $payload = json_decode($captured['body'], true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame([
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'user', 'content' => 'Hi'],
        ], $payload['messages']);
        $this->assertFalse($payload['stream']);
    }

    #[Test]
    public function chatDoesNotPrependSystemPromptWhenNotProvided(): void
    {
        $captured = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['body' => $options['body'] ?? null];

            return new MockResponse('{"message":{"role":"assistant","content":"{}"},"done":true}', ['http_code' => 200]);
        }, self::BASE_URI);

        $client = $this->makeClient(httpClient: $httpClient, enabled: true);

        $client->chat('llama3.2:3b', [['role' => 'user', 'content' => 'Hi']]);

        $this->assertIsString($captured['body']);
        /** @var array<string, mixed> $payload */
        $payload = json_decode($captured['body'], true, flags: \JSON_THROW_ON_ERROR);
        $this->assertSame([['role' => 'user', 'content' => 'Hi']], $payload['messages']);
    }

    // -------------------------------------------------------------------------
    // Error handling — transport / unreachable / invalid JSON
    // -------------------------------------------------------------------------

    #[Test]
    public function generateThrowsOllamaUnavailableExceptionOnTransportError(): void
    {
        $httpClient = new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Connection refused');
        }, self::BASE_URI);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Ollama request failed'));

        $client = $this->makeClient(httpClient: $httpClient, enabled: true, logger: $logger);

        $this->expectException(OllamaUnavailableException::class);
        $this->expectExceptionMessage('/api/generate');

        $client->generate('llama3.2:3b', 'prompt');
    }

    #[Test]
    public function generateThrowsOllamaUnavailableExceptionOnHttp5xx(): void
    {
        $httpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse('upstream down', ['http_code' => 503]), self::BASE_URI);

        $client = $this->makeClient(httpClient: $httpClient, enabled: true);

        $this->expectException(OllamaUnavailableException::class);

        $client->generate('llama3.2:3b', 'prompt');
    }

    #[Test]
    public function chatThrowsOllamaUnavailableExceptionOnInvalidJson(): void
    {
        $httpClient = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            'not-json-at-all',
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
        ), self::BASE_URI);

        $client = $this->makeClient(httpClient: $httpClient, enabled: true);

        $this->expectException(OllamaUnavailableException::class);

        $client->chat('llama3.2:3b', [['role' => 'user', 'content' => 'hi']]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeClient(
        ?MockHttpClient $httpClient = null,
        bool $enabled = true,
        ?LoggerInterface $logger = null,
    ): OllamaClient {
        return new OllamaClient(
            httpClient: $httpClient ?? new MockHttpClient([], self::BASE_URI),
            logger: $logger ?? new NullLogger(),
            enabled: $enabled,
        );
    }
}
