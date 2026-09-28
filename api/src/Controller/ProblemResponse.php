<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The API-wide RFC 7807 error body (tests/Functional/error-schema.json), for the plain Symfony
 * controllers. API Platform's ErrorListener only shapes errors raised by its own operations, so
 * an exception thrown from one of these routes would otherwise reach the client in Symfony's
 * own problem format, which carries none of the Hydra keys the clients read.
 */
final class ProblemResponse
{
    /**
     * @param array<string, mixed> $headers
     */
    public static function create(int $status, string $detail, array $headers = []): JsonResponse
    {
        return new JsonResponse([
            '@context' => '/contexts/Error',
            '@id' => '/errors/'.$status,
            '@type' => 'Error',
            'type' => '/errors/'.$status,
            'title' => 'An error occurred',
            'status' => $status,
            'detail' => $detail,
            'description' => $detail,
        ], $status, ['Content-Type' => 'application/problem+json; charset=utf-8'] + $headers);
    }

    public static function fromException(HttpExceptionInterface $exception): JsonResponse
    {
        $status = $exception->getStatusCode();
        $detail = '' !== $exception->getMessage() ? $exception->getMessage() : (Response::$statusTexts[$status] ?? 'An error occurred');

        return self::create($status, $detail, $exception->getHeaders());
    }
}
