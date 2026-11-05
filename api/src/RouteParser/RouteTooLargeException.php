<?php

declare(strict_types=1);

namespace App\RouteParser;

/**
 * A route that exceeds what one trip can hold: too many points, too many tours in a
 * collection, or a source response larger than any real route.
 *
 * A legitimate multi-week route is in the tens of thousands of points (ADR-004); the cap sits
 * well above that and well below what exhausts the request or worker memory.
 */
final class RouteTooLargeException extends \RuntimeException
{
    public const int MAX_POINTS = 200_000;

    public static function tooManyPoints(): self
    {
        return new self(\sprintf('The route exceeds the maximum of %d points.', self::MAX_POINTS));
    }
}
