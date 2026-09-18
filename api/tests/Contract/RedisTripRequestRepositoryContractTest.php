<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Repository\RedisTripRequestRepository;
use App\Repository\TripRequestRepositoryInterface;

/**
 * The implementation the whole functional suite actually runs on
 * (`config/services.php` aliases it in for the `test` environment).
 */
final class RedisTripRequestRepositoryContractTest extends TripRequestRepositoryContractTestCase
{
    #[\Override]
    protected function createRepository(): TripRequestRepositoryInterface
    {
        /** @var RedisTripRequestRepository $repository */
        $repository = self::getContainer()->get(RedisTripRequestRepository::class);

        return $repository;
    }
}
