<?php

declare(strict_types=1);

namespace App\RouteFetcher;

use App\RouteParser\RouteTooLargeException;

/**
 * Byte ceiling on a route source response, enforced on the stream: a `Content-Length` is a
 * claim, not a limit, and the body is buffered in the worker's memory.
 *
 * A Komoot tour page is around half a megabyte and a long Strava export a few megabytes; the
 * ceiling leaves an order of magnitude above that.
 */
final class RouteResponseSize
{
    public const int MAX_BYTES = 15 * 1024 * 1024;

    /** The `on_progress` option of a route source request. */
    public static function guard(): \Closure
    {
        return static function (int $downloaded, int $total): void {
            if ($downloaded > self::MAX_BYTES || $total > self::MAX_BYTES) {
                throw new RouteTooLargeException(\sprintf('The route source response exceeds %d bytes.', self::MAX_BYTES));
            }
        };
    }
}
