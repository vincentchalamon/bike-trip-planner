<?php

declare(strict_types=1);

namespace App\Mercure;

use ApiPlatform\Metadata\Operation;
use Symfony\Component\HttpFoundation\Request;

/**
 * Names the trip a response subscribes the browser to, for {@see MercureSubscriberListener}.
 *
 * Stamped on a request attribute by the provider or processor that knows the id, like
 * {@see \App\Concurrency\TripVersionEtag}, instead of the listener matching URLs and decoding
 * the response body to find it.
 */
final readonly class TripSubscription
{
    public const string ATTRIBUTE = '_mercure_trip_id';

    /**
     * Stamps only when `$owner` is the operation's own provider or processor. The share page
     * (`/s/{shortCode}`) and the MCP tools reuse these classes through a wrapper of their own,
     * and neither an anonymous reader nor an agent is handed a browser subscription.
     *
     * @param array<string, mixed> $context the API Platform operation context
     */
    public static function stamp(Operation $operation, array $context, string $tripId, string $owner): void
    {
        $request = $context['request'] ?? null;
        if ('' === $tripId || !$request instanceof Request
            || ($owner !== $operation->getProvider() && $owner !== $operation->getProcessor())) {
            return;
        }

        $request->attributes->set(self::ATTRIBUTE, $tripId);
    }
}
