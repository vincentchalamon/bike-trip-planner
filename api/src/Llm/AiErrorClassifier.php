<?php

declare(strict_types=1);

namespace App\Llm;

use App\Llm\Exception\AiFailureReason;
use App\Llm\Exception\AiUnavailableException;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

/**
 * Translates a provider/transport failure into a typed {@see AiUnavailableException}
 * (ADR-042) by inspecting the `symfony/ai-platform` bridge exceptions and the
 * underlying HTTP status, so callers can degrade precisely and only retry
 * transient failures (5xx/timeout and rate-limits with a Retry-After hint).
 */
final class AiErrorClassifier
{
    public function classify(\Throwable $exception, string $model): AiUnavailableException
    {
        [$reason, $retryAfter] = $this->resolve($exception);

        return new AiUnavailableException(
            \sprintf('AI request for model "%s" failed (%s): %s', $model, $reason->value, $exception->getMessage()),
            $reason,
            $retryAfter,
            previous: $exception,
        );
    }

    /**
     * @return array{AiFailureReason, int|null}
     */
    private function resolve(\Throwable $exception): array
    {
        if ($exception instanceof AuthenticationException) {
            return [AiFailureReason::INVALID_TOKEN, null];
        }

        if ($exception instanceof RateLimitExceededException) {
            $retryAfter = $exception->getRetryAfter();

            // A Retry-After hint marks a transient throttle; its absence points to
            // an exhausted plan/credit, which retrying would only waste.
            return [null !== $retryAfter ? AiFailureReason::RATE_LIMITED : AiFailureReason::QUOTA_EXCEEDED, $retryAfter];
        }

        if ($exception instanceof HttpExceptionInterface) {
            return $this->fromStatus($exception);
        }

        // Transport errors (timeout, connection refused) and any other platform
        // failure are treated as a transient outage.
        return [AiFailureReason::UNAVAILABLE, null];
    }

    /**
     * @return array{AiFailureReason, int|null}
     */
    private function fromStatus(HttpExceptionInterface $exception): array
    {
        $response = $exception->getResponse();
        $status = $response->getStatusCode();

        if (401 === $status || 403 === $status) {
            return [AiFailureReason::INVALID_TOKEN, null];
        }

        if (429 === $status) {
            $retryAfter = $this->retryAfter($response->getHeaders(false));

            return [null !== $retryAfter ? AiFailureReason::RATE_LIMITED : AiFailureReason::QUOTA_EXCEEDED, $retryAfter];
        }

        return [AiFailureReason::UNAVAILABLE, null];
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function retryAfter(array $headers): ?int
    {
        $value = $headers['retry-after'][0] ?? null;

        return null !== $value && ctype_digit($value) ? (int) $value : null;
    }
}
