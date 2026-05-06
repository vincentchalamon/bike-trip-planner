<?php

declare(strict_types=1);

namespace App\Llm;

use App\Llm\Exception\OllamaUnavailableException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin client over the Ollama HTTP API (https://github.com/ollama/ollama/blob/main/docs/api.md).
 *
 * Designed for local LLMs (LLaMA 3.x via Ollama). Returns parsed JSON payloads;
 * `format: "json"` is enabled by default so the model is constrained to emit valid JSON.
 *
 * When `ollama.enabled` is false, every call returns null without issuing any HTTP traffic.
 * When the LLM is enabled but unreachable or returns garbage, an OllamaUnavailableException
 * is thrown so the caller can decide between a graceful fallback or an explicit failure.
 */
final readonly class OllamaClient implements LlmClientInterface
{
    /**
     * Default number of context tokens. 8192 covers most prompts/responses while staying
     * cheap on CPU. Caller can override via the `num_ctx` option.
     */
    public const int DEFAULT_NUM_CTX = 8192;

    /**
     * Default temperature for analysis tasks (deterministic, low randomness).
     */
    public const float DEFAULT_TEMPERATURE = 0.3;

    public function __construct(
        #[Autowire(service: 'ollama.client')]
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        #[Autowire(env: 'bool:default::OLLAMA_ENABLED')]
        private bool $enabled,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function generate(string $model, string $prompt, ?string $systemPrompt = null, array $options = []): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'stream' => false,
            'format' => $options['format'] ?? 'json',
            'options' => $this->buildOptions($options),
        ];

        if (null !== $systemPrompt) {
            $payload['system'] = $systemPrompt;
        }

        return $this->postJson('/api/generate', $payload);
    }

    public function chat(string $model, array $messages, ?string $systemPrompt = null, array $options = []): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        $finalMessages = $messages;
        if (null !== $systemPrompt) {
            array_unshift($finalMessages, ['role' => 'system', 'content' => $systemPrompt]);
        }

        $payload = [
            'model' => $model,
            'messages' => $finalMessages,
            'stream' => false,
            'format' => $options['format'] ?? 'json',
            'options' => $this->buildOptions($options),
        ];

        return $this->postJson('/api/chat', $payload);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function buildOptions(array $options): array
    {
        // Drop top-level keys that belong to the request envelope, not to the LLM options.
        unset($options['format']);

        return [
            'num_ctx' => $options['num_ctx'] ?? self::DEFAULT_NUM_CTX,
            'temperature' => $options['temperature'] ?? self::DEFAULT_TEMPERATURE,
            ...$options,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     *
     * @throws OllamaUnavailableException
     */
    private function postJson(string $path, array $payload): array
    {
        try {
            $response = $this->httpClient->request('POST', $path, [
                'json' => $payload,
            ]);

            return $response->toArray();
        } catch (HttpExceptionInterface $exception) {
            $this->logger->warning('Ollama request failed.', [
                'path' => $path,
                'model' => $payload['model'] ?? null,
                'error' => $exception->getMessage(),
            ]);

            throw new OllamaUnavailableException(
                \sprintf('Ollama request to "%s" failed: %s', $path, $exception->getMessage()),
                previous: $exception,
            );
        }
    }
}
