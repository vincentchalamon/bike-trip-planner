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
     * The same number, promised as something stronger. See {@see self::stampValidator()}.
     */
    public const string VALIDATOR_ATTRIBUTE = '_trip_version_validator';

    /**
     * @param array<string, mixed> $context the API Platform operation context
     */
    public static function stamp(array $context, ?int $version): void
    {
        self::set($context, $version, self::ATTRIBUTE);
    }

    /**
     * Stamps the version as a genuine representation validator, for the rare resource where it
     * is one.
     *
     * {@see self::stamp()} produces a precondition token and pairs it with `no-store`, because
     * the version describes the *structure* and an enrichment rewrites `/trips/{id}/detail`
     * without moving it. A resource whose representation is the structure itself — the route
     * geometry: day numbers and coordinates, written only by `storeStages()`, which always
     * bumps — has no such gap, so its tag can be revalidated and answered 304.
     *
     * It is a separate attribute rather than a flag because the two must never be confused:
     * `no-store` forbids the client from keeping a copy at all, so a response carrying it can
     * never come back with `If-None-Match`, and stamping both would be a contradiction.
     *
     * @param array<string, mixed> $context the API Platform operation context
     */
    public static function stampValidator(array $context, ?int $version): void
    {
        self::set($context, $version, self::VALIDATOR_ATTRIBUTE);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function set(array $context, ?int $version, string $attribute): void
    {
        $request = $context['request'] ?? null;
        if (null === $version || !$request instanceof Request) {
            return;
        }

        $request->attributes->set($attribute, $version);
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
