<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Repository\TripRequestRepositoryInterface;

/**
 * Reads back the identifier of the stage sitting at a given position.
 *
 * The URLs name a stage by identity (ADR-066), but the tests still describe their intent
 * positionally — "the second stage", "the one after the rest day". Rather than threading
 * identifiers out of every seeding helper, the position is resolved against what was
 * actually persisted, at the moment the request is made.
 */
trait AddressesStagesByIdTrait
{
    private function stageIdAt(string $tripId, int $position): string
    {
        /** @var TripRequestRepositoryInterface $repository */
        $repository = self::getContainer()->get(TripRequestRepositoryInterface::class);

        $stages = $repository->getStages($tripId) ?? [];
        self::assertArrayHasKey($position, $stages, \sprintf('No stage seeded at position %d.', $position));

        return $stages[$position]->id;
    }
}
