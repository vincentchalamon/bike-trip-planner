<?php

declare(strict_types=1);

namespace App\Llm;

use App\Llm\Exception\OllamaUnavailableException;

/**
 * LLM client abstraction (Dependency Inversion Principle).
 *
 * Allows swapping Ollama for another local/remote LLM backend without touching
 * call sites. When the underlying client is disabled (feature flag off), implementations
 * should return null so callers can short-circuit gracefully.
 */
interface LlmClientInterface
{
    /**
     * Returns true when the underlying LLM is configured and ready to serve requests.
     *
     * Implementations MUST return false when the corresponding feature flag is off,
     * so that callers can avoid issuing any HTTP traffic.
     */
    public function isEnabled(): bool;

    /**
     * Single-shot completion.
     *
     * @param string               $model        e.g. "llama3.2:3b" or "llama3.1:8b"
     * @param string               $prompt       user prompt
     * @param string|null          $systemPrompt optional system instruction
     * @param array<string, mixed> $options      generation options (temperature, num_ctx, format, ...)
     *
     * @return array<string, mixed>|null parsed JSON response, or null when the client is disabled
     *
     * @throws OllamaUnavailableException when the LLM is enabled but unreachable or returns an invalid response
     */
    public function generate(string $model, string $prompt, ?string $systemPrompt = null, array $options = []): ?array;

    /**
     * Multi-turn chat completion.
     *
     * @param string                                     $model        e.g. "llama3.2:3b" or "llama3.1:8b"
     * @param list<array{role: string, content: string}> $messages     conversation history
     * @param string|null                                $systemPrompt optional system instruction prepended to the conversation
     * @param array<string, mixed>                       $options      generation options (temperature, num_ctx, format, ...)
     *
     * @return array<string, mixed>|null parsed JSON response, or null when the client is disabled
     *
     * @throws OllamaUnavailableException when the LLM is enabled but unreachable or returns an invalid response
     */
    public function chat(string $model, array $messages, ?string $systemPrompt = null, array $options = []): ?array;
}
