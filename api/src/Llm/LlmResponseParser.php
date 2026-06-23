<?php

declare(strict_types=1);

namespace App\Llm;

/**
 * Extracts the assistant text from an {@see LlmClientInterface} response envelope.
 *
 * Tolerates the two shapes the providers emit: the chat shape
 * `{ message: { content: "..." } }` and the generate shape `{ response: "..." }`.
 * Returns null when neither carries usable text.
 */
final readonly class LlmResponseParser
{
    /**
     * @param array<string, mixed> $response
     */
    public function extractText(array $response): ?string
    {
        if (isset($response['message']) && \is_array($response['message'])) {
            $content = $response['message']['content'] ?? null;
            if (\is_string($content)) {
                return $content;
            }
        }

        if (isset($response['response']) && \is_string($response['response'])) {
            return $response['response'];
        }

        return null;
    }
}
