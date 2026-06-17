<?php

declare(strict_types=1);

namespace App\Llm\Exception;

/**
 * Thrown when a user's configured AI provider cannot be reached or returns an
 * unrecoverable error. Provider-neutral successor to OllamaUnavailableException
 * (ADR-042). A typed failure reason (invalid token / quota / rate-limit /
 * unavailable) is layered on in a follow-up so callers can degrade precisely.
 */
class AiUnavailableException extends \RuntimeException
{
}
