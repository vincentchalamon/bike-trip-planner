<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Repository\DoctrineTripRequestRepository;
use App\Repository\TripRequestRepositoryInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The implementation dev and prod run on, and the one no functional test touches — which
 * is exactly why it needs the contract.
 */
#[ResetDatabase]
final class DoctrineTripRequestRepositoryContractTest extends TripRequestRepositoryContractTestCase
{
    #[\Override]
    protected function createRepository(): TripRequestRepositoryInterface
    {
        /** @var DoctrineTripRequestRepository $repository */
        $repository = self::getContainer()->get(DoctrineTripRequestRepository::class);

        return $repository;
    }
}
