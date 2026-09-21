<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\Client;
use App\Concurrency\IfMatch;

/**
 * A client for suites that edit a trip without being about concurrency.
 *
 * Every structural edit requires an `If-Match` precondition, so each of these requests would
 * otherwise be answered 428. They send `*` — "whatever the current version is" (RFC 9110
 * §13.1.1) — which keeps them testing what they are about while still going through the
 * precondition path.
 *
 * The semantics of the header itself — a missing one, a stale one, the absence of a version
 * oracle across trips — are covered by {@see TripPreconditionTest}, which pins real versions
 * rather than the wildcard.
 */
trait EditsTripsTrait
{
    private static function createEditingClient(): Client
    {
        return self::createClient([], ['headers' => [IfMatch::HEADER => '*']]);
    }
}
