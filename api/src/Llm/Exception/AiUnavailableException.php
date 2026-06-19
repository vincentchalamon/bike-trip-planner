<?php

declare(strict_types=1);

namespace App\Llm\Exception;

/**
 * Thrown when a user's configured AI provider cannot be reached or returns an
 * unrecoverable error (ADR-042). Carries a typed {@see AiFailureReason} (and an optional Retry-After
 * hint) so callers can degrade precisely and only retry transient failures.
 */
class AiUnavailableException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly AiFailureReason $reason = AiFailureReason::UNAVAILABLE,
        private readonly ?int $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public function getReason(): AiFailureReason
    {
        return $this->reason;
    }

    /**
     * Provider-suggested delay in seconds before retrying, when known.
     */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    public function isTransient(): bool
    {
        return $this->reason->isTransient();
    }
}
