<?php

declare(strict_types=1);

namespace App\Llm;

use App\Llm\Exception\OllamaUnavailableException;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformExceptionInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;

/**
 * Façade over the symfony/ai-platform Ollama bridge that preserves the legacy
 * Ollama HTTP response shape (`['response' => ...]` / `['message' => ['content' => ...]]`)
 * so existing callers (analyze handlers, intent detector, in-ride assistant,
 * chat processor) keep working unchanged.
 *
 * When `ollama.enabled` is false, every call short-circuits to null without
 * touching the platform. Transport or platform errors are wrapped into
 * {@see OllamaUnavailableException} so the existing graceful-fallback logic
 * upstream still applies.
 */
final readonly class OllamaClient implements LlmClientInterface
{
    public const int DEFAULT_NUM_CTX = 8192;

    public const float DEFAULT_TEMPERATURE = 0.3;

    public function __construct(
        private PlatformInterface $platform,
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

        $messages = [];
        if (null !== $systemPrompt) {
            $messages[] = Message::forSystem($systemPrompt);
        }

        $messages[] = Message::ofUser($prompt);

        return ['response' => $this->invoke($model, $messages, $options)];
    }

    public function chat(string $model, array $messages, ?string $systemPrompt = null, array $options = []): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        $bag = [];
        if (null !== $systemPrompt) {
            $bag[] = Message::forSystem($systemPrompt);
        }

        foreach ($messages as $message) {
            $bag[] = match ($message['role']) {
                'system' => Message::forSystem($message['content']),
                'assistant' => Message::ofAssistant($message['content']),
                default => Message::ofUser($message['content']),
            };
        }

        return ['message' => ['role' => 'assistant', 'content' => $this->invoke($model, $bag, $options)]];
    }

    /**
     * @param list<MessageInterface> $messages
     * @param array<string, mixed>   $options
     */
    private function invoke(string $model, array $messages, array $options): string
    {
        if ('' === $model) {
            throw new \InvalidArgumentException('Model name must not be empty.');
        }

        $options = $this->normalizeOptions($options);

        try {
            return $this->platform->invoke($model, new MessageBag(...$messages), $options)->asText();
        } catch (PlatformExceptionInterface|HttpExceptionInterface $exception) {
            $this->logger->warning('Ollama request failed.', [
                'model' => $model,
                'error' => $exception->getMessage(),
            ]);

            throw new OllamaUnavailableException(\sprintf('Ollama request for model "%s" failed: %s', $model, $exception->getMessage()), previous: $exception);
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function normalizeOptions(array $options): array
    {
        $options['num_ctx'] ??= self::DEFAULT_NUM_CTX;
        $options['temperature'] ??= self::DEFAULT_TEMPERATURE;
        $options['format'] ??= 'json';

        return $options;
    }
}
