<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\TripShare;

/**
 * Hands the create processor a blank TripShare.
 *
 * The POST shares its URI template with GET/DELETE on /trips/{tripId}/share, so
 * the default Doctrine item provider would resolve the link by running
 * getOneOrNullResult() over every share of the trip — with no `deletedAt`
 * filter and no LIMIT. As soon as a trip has two or more revoked shares that
 * throws NonUniqueResultException (HTTP 500), permanently blocking every new
 * share attempt (recette #649). TripShareCreateProcessor builds a fresh share
 * regardless of the read result, so a blank instance is all it needs.
 *
 * @implements ProviderInterface<TripShare>
 */
final readonly class TripShareCreateProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TripShare
    {
        return new TripShare();
    }
}
