<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Raised when a trip cannot be served to the caller — whether it never existed,
 * has expired from storage, or is owned by another user.
 *
 * The three cases deliberately share one response: an authenticated caller
 * probing trip UUIDs must not be able to tell "exists but not mine" from "does
 * not exist" (ADR-038). The message is therefore fixed and never echoes the
 * requested id, so the foreign and missing responses are byte-for-byte equal.
 */
final class TripNotFoundException extends NotFoundHttpException
{
    public const string MESSAGE = 'Trip not found or has expired.';

    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(self::MESSAGE, $previous);
    }
}
