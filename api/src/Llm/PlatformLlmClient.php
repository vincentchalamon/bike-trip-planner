<?php

declare(strict_types=1);

namespace App\Llm;

use App\Llm\Exception\AiUnavailableException;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformExceptionInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;

/**
 * Provider-agnostic LLM client over a `symfony/ai-platform` Platform (ADR-042),
 * built per-user by {@see LlmClientFactory} for the user's chosen provider/token.
 * Generalises the former OllamaClient: uses `->asText()` (neutral across
 * providers) and does NOT force Ollama-only options (`format`, `num_ctx`) — JSON
 * output is requested via the prompt and parsed leniently downstream, since the
 * JSON mode differs per provider.
 *
 * Returns the same lightweight shapes the existing consumers expect
 * (`['response' => ...]` / `['message' => ['content' => ...]]`). Transport or
 * platform errors are wrapped into {@see AiUnavailableException} for graceful
 * fallback upstream.
 */
final readonly class PlatformLlmClient implements LlmClientInterface
{
    public function __construct(
        private PlatformInterface $platform,
    ) {
    }

    public function isEnabled(): bool
    {
        // A constructed client always corresponds to a configured provider; the
        // "is AI configured?" decision lives in LlmClientFactory::forUser().
        return true;
    }

    public function generate(string $model, string $prompt, ?string $systemPrompt = null, array $options = []): array
    {
        $messages = [];
        if (null !== $systemPrompt) {
            $messages[] = Message::forSystem($systemPrompt);
        }

        $messages[] = Message::ofUser($prompt);

        return ['response' => $this->invoke($model, $messages, $options)];
    }

    public function chat(string $model, array $messages, ?string $systemPrompt = null, array $options = []): array
    {
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

        try {
            return $this->platform->invoke($model, new MessageBag(...$messages), $options)->asText();
        } catch (PlatformExceptionInterface|HttpExceptionInterface $exception) {
            throw new AiUnavailableException(\sprintf('AI request for model "%s" failed: %s', $model, $exception->getMessage()), $exception->getCode(), previous: $exception);
        }
    }
}
