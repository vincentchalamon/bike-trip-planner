<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Repository\DoctrineTripRequestRepository;
use App\Repository\TripRequestRepositoryInterface;
use App\Repository\TripStageStoreInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The implementation dev and prod run on, reached without the lock decorator.
 */
#[ResetDatabase]
final class DoctrineTripStageStoreContractTest extends TripStageStoreContractTestCase
{
    #[\Override]
    protected function createStore(): TripStageStoreInterface
    {
        /** @var DoctrineTripRequestRepository $store */
        $store = self::getContainer()->get(DoctrineTripRequestRepository::class);

        return $store;
    }

    #[\Override]
    protected function createTripRepository(): TripRequestRepositoryInterface
    {
        /** @var DoctrineTripRequestRepository $repository */
        $repository = self::getContainer()->get(DoctrineTripRequestRepository::class);

        return $repository;
    }
}
