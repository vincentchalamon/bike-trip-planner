<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage as StageDto;
use App\ApiResource\TripRequest;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Proves that stage writes are actually serialised, without racing two processes and
 * hoping the timing lands the right way.
 *
 * The lock is exercised through the decorated interface, so this covers whichever
 * implementation the environment resolves to.
 */
#[ResetDatabase]
final class LockingTripRequestRepositoryTest extends KernelTestCase
{
    private TripRequestRepositoryInterface $repository;

    private LockFactory $lockFactory;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        /** @var TripRequestRepositoryInterface $repository */
        $repository = $container->get(TripRequestRepositoryInterface::class);
        $this->repository = $repository;

        /** @var LockFactory $lockFactory */
        $lockFactory = $container->get(LockFactory::class);
        $this->lockFactory = $lockFactory;
    }

    /**
     * From inside the mutation, a *separate* lock instance — what another process would
     * hold — must fail to take the same key. If it succeeds, the critical section is not
     * one.
     */
    #[Test]
    public function noOneElseCanWriteWhileAMutationIsRunning(): void
    {
        $tripId = $this->seedTrip();
        $observed = null;

        $this->repository->mutateStages($tripId, function (array $stages) use ($tripId, &$observed): array {
            $observed = $this->lockFactory
                ->createLock(\sprintf('trip.%s.stages.update', $tripId), 5)
                ->acquire();

            return $stages;
        });

        self::assertFalse($observed, 'A concurrent writer took the lock while a mutation held it.');
    }

    /** The lock must be released once the mutation returns, or the next write deadlocks. */
    #[Test]
    public function theLockIsReleasedAfterTheMutation(): void
    {
        $tripId = $this->seedTrip();

        $this->repository->mutateStages($tripId, static fn (array $stages): array => $stages);

        $lock = $this->lockFactory->createLock(\sprintf('trip.%s.stages.update', $tripId), 5);
        self::assertTrue($lock->acquire());
        $lock->release();
    }

    /** ...including when the mutation throws, which every processor does on a bad index. */
    #[Test]
    public function theLockIsReleasedWhenTheMutationThrows(): void
    {
        $tripId = $this->seedTrip();

        try {
            $this->repository->mutateStages($tripId, static function (array $stages): array {
                throw new \RuntimeException('rejected');
            });
            self::fail('The mutation should have propagated its exception.');
        } catch (\RuntimeException) {
            // expected
        }

        $lock = $this->lockFactory->createLock(\sprintf('trip.%s.stages.update', $tripId), 5);
        self::assertTrue($lock->acquire());
        $lock->release();
    }

    /**
     * A nested write must not wait on the lock this very process already holds.
     * createLock() mints a fresh token each call, so without the re-entrance guard this
     * would hang until the acquisition timeout and then fail.
     */
    #[Test]
    public function aNestedWriteDoesNotDeadlockOnOurOwnLock(): void
    {
        $tripId = $this->seedTrip();

        $this->repository->mutateStages($tripId, function (array $stages) use ($tripId): array {
            $this->repository->updateStageWeather($tripId, $stages[0]->id, null);

            return $stages;
        });

        self::assertNotNull($this->repository->getStages($tripId));
    }

    /** A writer that cannot get in is refused, not left hanging on a PHP-FPM worker. */
    #[Test]
    public function aWriterRefusedTheLockGetsAConflictRatherThanBlocking(): void
    {
        $tripId = $this->seedTrip();

        $holder = $this->lockFactory->createLock(\sprintf('trip.%s.stages.update', $tripId), 30);
        self::assertTrue($holder->acquire());

        try {
            $this->expectException(ConflictHttpException::class);
            $this->repository->mutateStages($tripId, static fn (array $stages): array => $stages);
        } finally {
            $holder->release();
        }
    }

    /**
     * The scenario the whole lot exists for: a worker persists one enrichment column
     * while a processor is holding a snapshot of the collection. Interleaved by hand
     * here, so the outcome does not depend on timing.
     */
    #[Test]
    public function aConcurrentEnrichmentWriteIsNotRevertedByTheEditThatFollows(): void
    {
        $tripId = $this->seedTrip();
        $stages = $this->repository->getStages($tripId) ?? [];
        $weather = $this->weather();

        // The worker writes weather on stage 1 *before* the edit commits.
        $this->repository->updateStageWeather($tripId, $stages[0]->id, $weather);

        // The edit renames stage 2, from a snapshot taken before that weather existed.
        $this->repository->mutateStages($tripId, static function (array $stages): array {
            $stages[1]->label = 'edited';

            return $stages;
        });

        $after = $this->repository->getStages($tripId) ?? [];
        self::assertSame('edited', $after[1]->label);
        self::assertInstanceOf(WeatherForecast::class, $after[0]->weather, 'The concurrent weather write was reverted by the edit.');
    }

    private function seedTrip(): string
    {
        $tripId = Uuid::v7()->toRfc4122();
        $this->repository->initializeTrip($tripId, new TripRequest(Uuid::fromString($tripId)));
        $this->repository->storeStages($tripId, [
            $this->stage($tripId, 1),
            $this->stage($tripId, 2),
        ]);

        return $tripId;
    }

    private function stage(string $tripId, int $dayNumber): StageDto
    {
        return new StageDto(
            tripId: $tripId,
            dayNumber: $dayNumber,
            distance: 40.0,
            elevation: 200.0,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate(48.2, 2.2),
        );
    }

    private function weather(): WeatherForecast
    {
        return new WeatherForecast(
            icon: '10d',
            description: 'Rain',
            tempMin: 12.0,
            tempMax: 18.0,
            windSpeed: 10.0,
            windDirection: 'N',
            precipitationProbability: 80,
            humidity: 70,
            comfortIndex: 90,
            relativeWindDirection: WeatherForecast::RELATIVE_WIND_UNKNOWN,
        );
    }
}
