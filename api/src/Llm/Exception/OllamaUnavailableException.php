<?php

declare(strict_types=1);

namespace App\Llm\Exception;

/**
 * Thrown when the (legacy) Ollama LLM service cannot be reached or returns an
 * unrecoverable error. Specialisation of {@see AiUnavailableException} so the
 * provider-neutral contract holds while the self-hosted Ollama path is phased
 * out (ADR-042); always reports the UNAVAILABLE reason.
 */
final class OllamaUnavailableException extends AiUnavailableException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, AiFailureReason::UNAVAILABLE, null, $previous);
    }
}
