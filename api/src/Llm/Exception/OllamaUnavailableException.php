<?php

declare(strict_types=1);

namespace App\Llm\Exception;

/**
 * Thrown when the Ollama LLM service cannot be reached or returns an unrecoverable error.
 *
 * Callers may catch this exception to perform a graceful fallback (e.g. skip enrichment)
 * or rethrow it to fail explicitly when the LLM is a hard dependency.
 */
final class OllamaUnavailableException extends \RuntimeException
{
}
