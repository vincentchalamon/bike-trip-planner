<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Post;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Entity\User;
use App\Repository\TripRequestRepositoryInterface;
use App\State\TripDuplicateProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class TripDuplicateProcessorTest extends TestCase
{
    #[Test]
    public function duplicateIsRateLimited(): void
    {
        // SEC-009: once the per-user duplicate limiter is exhausted, the endpoint
        // must reject with 429 before cloning any DB rows / Redis blobs.
        $user = new User('dup@example.com');

        $limiter = new RateLimiterFactory(
            ['id' => 'trip_duplicate', 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
        $limiter->create($user->getId()->toRfc4122())->consume();

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $processor = new TripDuplicateProcessor(
            $this->createStub(TripRequestRepositoryInterface::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(ComputationTrackerInterface::class),
            $this->createStub(TripGenerationTrackerInterface::class),
            $security,
            $this->createStub(CacheItemPoolInterface::class),
            $limiter,
        );

        $this->expectException(TooManyRequestsHttpException::class);
        $processor->process(new TripRequest(), new Post(), ['id' => 't']);
    }
}
