<?php

declare(strict_types=1);

namespace App\Llm;

use App\Llm\Exception\AiUnavailableException;

/**
 * LLM client abstraction (Dependency Inversion Principle).
 *
 * Allows swapping the LLM backend without touching call sites. Whether AI is
 * available is decided per-user upstream (ADR-042): the factory returns null when
 * no provider/token is configured (or the token cannot be decrypted), so a client
 * instance always corresponds to a usable provider.
 */
interface LlmClientInterface
{
    /**
     * Returns true when the client is ready to serve requests. A constructed
     * client always is (the "is AI configured?" decision lives in the factory),
     * so this is retained only for the interface contract.
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
     * @throws AiUnavailableException when the provider is reachable-but-failing or returns an invalid response
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
     * @throws AiUnavailableException when the provider is reachable-but-failing or returns an invalid response
     */
    public function chat(string $model, array $messages, ?string $systemPrompt = null, array $options = []): ?array;
}
