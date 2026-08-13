<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MercureToken;
use App\Mercure\MercureTokenIssuer;

/**
 * Issues a Mercure subscriber JWT for the requested trip.
 *
 * Ownership is enforced by the operation's `security` expression before this
 * runs; the returned token is only ever surfaced to a caller the voter granted
 * (a non-owner is turned into a 404 upstream, ADR-038).
 *
 * @implements ProviderInterface<MercureToken>
 */
final readonly class MercureTokenProvider implements ProviderInterface
{
    public function __construct(
        private MercureTokenIssuer $tokenIssuer,
    ) {
    }

    /**
     * @param array{id?: string}   $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MercureToken
    {
        $id = $uriVariables['id'] ?? '';

        return new MercureToken($this->tokenIssuer->generateSubscriberToken($id));
    }
}
