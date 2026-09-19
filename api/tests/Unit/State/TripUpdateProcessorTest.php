<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Patch;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationDependencyResolver;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Message\ScanAccommodations;
use App\Repository\TripRequestRepositoryInterface;
use App\State\IdempotencyCheckerInterface;
use App\State\TripLocker;
use App\State\TripUpdateProcessor;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[AllowMockObjectsWithoutExpectations]
final class TripUpdateProcessorTest extends TestCase
{
    private MockObject&TripRequestRepositoryInterface $tripStateManager;

    private MockObject&MessageBusInterface $messageBus;

    private MockObject&ComputationTrackerInterface $computationTracker;

    private MockObject&IdempotencyCheckerInterface $idempotencyChecker;

    private TripUpdateProcessor $processor;

    #[\Override]
    protected function setUp(): void
    {
        $this->tripStateManager = $this->createMock(TripRequestRepositoryInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->computationTracker = $this->createMock(ComputationTrackerInterface::class);
        $this->idempotencyChecker = $this->createMock(IdempotencyCheckerInterface::class);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User('owner@example.com'));

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);
        $generationTracker->method('increment')->willReturn(2);
        $generationTracker->method('current')->willReturn(1);

        $this->processor = new TripUpdateProcessor(
            $this->messageBus,
            $this->tripStateManager,
            $this->computationTracker,
            new ComputationDependencyResolver(),
            $this->idempotencyChecker,
            $generationTracker,
            $security,
            new TripLocker(),
        );
    }

    #[Test]
    public function lockedTripThrowsHttpException(): void
    {
        $lockedRequest = new TripRequest();
        $lockedRequest->startDate = new \DateTimeImmutable('yesterday');

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getRequest')->willReturn($lockedRequest);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User('owner@example.com'));

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);
        $generationTracker->method('increment')->willReturn(1);
        $generationTracker->method('current')->willReturn(0);

        $processor = new TripUpdateProcessor(
            $this->createStub(MessageBusInterface::class),
            $tripStateManager,
            $this->createStub(ComputationTrackerInterface::class),
            new ComputationDependencyResolver(),
            $this->createStub(IdempotencyCheckerInterface::class),
            $generationTracker,
            $security,
            new TripLocker(),
        );

        try {
            $processor->process(new TripRequest(), new Patch(), ['id' => 'trip-1']);
            self::fail('Expected HttpException to be thrown.');
        } catch (HttpException $httpException) {
            self::assertSame(423, $httpException->getStatusCode());
        }
    }

    #[Test]
    public function dispatchesAccommodationsScanWithEnabledTypesWhenTypesChange(): void
    {
        $tripId = 'trip-acc';
        $enabledTypes = ['camp_site', 'hostel'];

        $oldRequest = new TripRequest();
        $oldRequest->sourceUrl = 'https://www.komoot.com/tour/123';
        $oldRequest->enabledAccommodationTypes = ['camp_site', 'hostel', 'alpine_hut'];

        $newRequest = new TripRequest();
        $newRequest->sourceUrl = 'https://www.komoot.com/tour/123';
        $newRequest->enabledAccommodationTypes = $enabledTypes;

        // First call: get old request for dependency resolution
        // Second call (inside dispatchAccommodationsScan): get stored request for enabled types
        $this->tripStateManager->method('getRequest')
            ->willReturnOnConsecutiveCalls($oldRequest, $newRequest);
        $this->computationTracker->method('getStatuses')->willReturn([]);

        $this->idempotencyChecker->method('hasChanged')->willReturn(true);

        $dispatchedMessages = [];
        $this->messageBus->method('dispatch')
            ->willReturnCallback(static function (object $msg) use (&$dispatchedMessages): Envelope {
                $dispatchedMessages[] = $msg;

                return new Envelope($msg);
            });

        $this->processor->process($newRequest, new Patch(), ['id' => $tripId]);

        $scanMessages = array_values(array_filter(
            $dispatchedMessages,
            static fn (object $m): bool => $m instanceof ScanAccommodations,
        ));

        $this->assertCount(1, $scanMessages);
        $this->assertSame($tripId, $scanMessages[0]->tripId);
        $this->assertSame($enabledTypes, $scanMessages[0]->enabledAccommodationTypes);
    }

    /**
     * Regression (#1292 review): the settings comparison has to run before the write.
     *
     * {@see \App\Repository\DoctrineTripRequestRepository} hands out the managed entity and
     * `storeRequest()` copies the incoming fields onto that very instance, so a comparison
     * performed afterwards compares the new values with themselves and dispatches nothing.
     * The functional suite cannot see it — it is aliased to the Redis implementation, which
     * deserialises a fresh copy per read — so the aliasing is reproduced here instead.
     */
    #[Test]
    public function resolvesTheChangeBeforeTheWriteAliasesTheOldRequest(): void
    {
        $tripId = 'trip-alias';

        $managed = new TripRequest();
        $managed->sourceUrl = 'https://www.komoot.com/tour/123';
        $managed->maxDistancePerDay = 80.0;

        $incoming = new TripRequest();
        $incoming->sourceUrl = 'https://www.komoot.com/tour/123';
        $incoming->maxDistancePerDay = 120.0;

        $this->tripStateManager->method('getRequest')->willReturn($managed);
        // What Doctrine does: the write lands on the object the reads handed out.
        $this->tripStateManager->method('storeRequest')
            ->willReturnCallback(static function (string $id, TripRequest $source) use ($managed): void {
                $managed->maxDistancePerDay = $source->maxDistancePerDay;
            });
        $this->computationTracker->method('getStatuses')->willReturn([]);
        $this->idempotencyChecker->method('hasChanged')->willReturn(true);

        $dispatched = [];
        $this->messageBus->method('dispatch')
            ->willReturnCallback(static function (object $msg) use (&$dispatched): Envelope {
                $dispatched[] = $msg;

                return new Envelope($msg);
            });

        $this->processor->process($incoming, new Patch(), ['id' => $tripId]);

        $this->assertNotSame([], $dispatched, 'A pacing change must re-dispatch its computations; resolving after the write compares the new settings with themselves and dispatches nothing.');
    }

    #[Test]
    public function doesNotDispatchWhenNothingChanged(): void
    {
        $tripId = 'trip-no-change';

        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/123';

        $this->tripStateManager->method('getRequest')->willReturn($request);
        $this->computationTracker->method('getStatuses')->willReturn([]);

        $this->idempotencyChecker->method('hasChanged')->willReturn(false);

        $this->messageBus->expects($this->never())->method('dispatch');

        $this->processor->process($request, new Patch(), ['id' => $tripId]);
    }
}
