<?php

declare(strict_types=1);

namespace App\Concurrency;

use Symfony\Component\HttpFoundation\Request;

/**
 * Carries the trip version a provider or processor observed, so the response can advertise
 * it as an `ETag` the client sends back with `If-Match`.
 *
 * The value rides on a request attribute rather than being re-read by the listener, because
 * re-reading it would hand the client a tag for a state it has not seen: a worker bumping the
 * version between the write and the read would make the client believe it is up to date with
 * a version it never received, and its next edit would succeed where it should have been
 * refused. The producers each stamp the version from inside the read or write that produced
 * the body.
 */
final readonly class TripVersionEtag
{
    public const string ATTRIBUTE = '_trip_version';

    /**
     * @param array<string, mixed> $context the API Platform operation context
     */
    public static function stamp(array $context, ?int $version): void
    {
        $request = $context['request'] ?? null;
        if (null === $version || !$request instanceof Request) {
            return;
        }

        $request->attributes->set(self::ATTRIBUTE, $version);
    }

    /**
     * A **strong** validator, deliberately. `If-Match` mandates the strong comparison
     * function (RFC 9110 §8.8.3.2), under which a weak tag never matches — `W/"7"` would
     * have made the header inert. The price is that the version is not a byte-exact
     * representation validator (an enrichment changes the body without moving it), which is
     * why every response carrying it is also `Cache-Control: no-store`: the tag is a
     * precondition token, and nothing is allowed to use it as a cache validator.
     */
    public static function value(int $version): string
    {
        return \sprintf('"%d"', $version);
    }
}
