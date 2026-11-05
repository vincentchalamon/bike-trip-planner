<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use App\Mercure\TripUpdatePublisherInterface;
use App\Service\EnrichmentMessageFactory;
use App\Service\TripAnalysisDispatcher;
use App\Service\TripCompletionGate;
use Psr\Log\NullLogger;
use ApiPlatform\Metadata\Patch;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ParameterChangeResolver;
use App\ComputationTracker\ComputationSupersession;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Concurrency\IfMatch;
use App\Message\ScanAccommodations;
use App\Repository\TripRequestRepositoryInterface;
use App\State\TripLocker;
use App\State\TripUpdateProcessor;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

#[AllowMockObjectsWithoutExpectations]
final class TripUpdateProcessorTest extends TestCase
{
    private MockObject&TripRequestRepositoryInterface $tripStateManager;

    private MockObject&MessageBusInterface $messageBus;

    private MockObject&ComputationTrackerInterface $computationTracker;

    private TripUpdateProcessor $processor;

    private User $owner;

    #[\Override]
    protected function setUp(): void
    {
        $this->tripStateManager = $this->createMock(TripRequestRepositoryInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->computationTracker = $this->createMock(ComputationTrackerInterface::class);

        $this->owner = new User('owner@example.com');
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->owner);

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);
        $generationTracker->method('increment')->willReturn(2);
        $generationTracker->method('current')->willReturn(1);

        $this->processor = new TripUpdateProcessor(
            $this->messageBus,
            $this->tripStateManager,
            $this->computationTracker,
            new ParameterChangeResolver(),
            $generationTracker,
            $security,
            new TripLocker(new NativeClock()),
            new TripAnalysisDispatcher($this->messageBus, new EnrichmentMessageFactory()),
            $this->inertSupersession(),
            $this->limiter(10),
            new NativeClock(),
        );
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

        // The only read left is the one inside dispatchAccommodationsScan, which wants the
        // stored (new) types. The old ones now arrive as the before-image, from the context.
        $this->tripStateManager->method('getRequest')->willReturn($newRequest);
        $this->computationTracker->method('getStatuses')->willReturn([]);


        $dispatchedMessages = [];
        $this->messageBus->method('dispatch')
            ->willReturnCallback(static function (object $msg) use (&$dispatchedMessages): Envelope {
                $dispatchedMessages[] = $msg;

                return new Envelope($msg);
            });

        $this->processor->process($newRequest, new Patch(), ['id' => $tripId], ['previous_data' => $oldRequest]);

        $scanMessages = array_values(array_filter(
            $dispatchedMessages,
            static fn (object $m): bool => $m instanceof ScanAccommodations,
        ));

        $this->assertCount(1, $scanMessages);
        $this->assertSame($tripId, $scanMessages[0]->tripId);
        $this->assertSame($enabledTypes, $scanMessages[0]->enabledAccommodationTypes);
    }

    /**
     * The settings edit carries the client's `If-Match` into the version bump.
     *
     * The comparison happens in the repository, under the write lock; what is checkable here
     * is the wiring — and the wiring is what rots silently, since the shared stub above
     * answers `increment()` the same way whatever it is handed (#1292 review).
     */
    #[Test]
    public function carriesTheClientPreconditionIntoTheVersionBump(): void
    {
        $generationTracker = $this->createMock(TripGenerationTrackerInterface::class);
        $generationTracker->expects(self::once())
            ->method('increment')
            ->with('trip-precondition', 7)
            ->willReturn(8);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User('owner@example.com'));

        $processor = new TripUpdateProcessor(
            $this->messageBus,
            $this->tripStateManager,
            $this->computationTracker,
            new ParameterChangeResolver(),
            $generationTracker,
            $security,
            new TripLocker(new NativeClock()),
            new TripAnalysisDispatcher($this->messageBus, new EnrichmentMessageFactory()),
            $this->inertSupersession(),
            $this->limiter(10),
            new NativeClock(),
        );

        $old = new TripRequest();
        $old->sourceUrl = 'https://www.komoot.com/tour/123';
        $old->maxDistancePerDay = 80.0;

        $incoming = new TripRequest();
        $incoming->sourceUrl = 'https://www.komoot.com/tour/123';
        $incoming->maxDistancePerDay = 120.0;

        $this->tripStateManager->method('getRequest')->willReturn($old);
        $this->computationTracker->method('getStatuses')->willReturn([]);
        $this->messageBus->method('dispatch')
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $request = new Request();
        $request->headers->set(IfMatch::HEADER, '"7"');

        $processor->process($incoming, new Patch(), ['id' => 'trip-precondition'], ['request' => $request, 'previous_data' => $old]);
    }

    /**
     * Regression (#1292 review, then the lot D audit): the comparison must not touch the
     * repository at all.
     *
     * {@see \App\Repository\DoctrineTripRequestRepository} hands out the managed entity, and
     * API Platform deserialises the PATCH body into that very instance — so *every* read of
     * it, before the write as well as after, already carries the new values. Resolving
     * against it compared the new settings with themselves and dispatched nothing, for every
     * PATCH ever made. Moving the comparison earlier was not enough; the before-image has to
     * come from `previous_data`, which ReadProvider clones before deserialisation.
     *
     * The aliasing is reproduced here: `getRequest()` returns the object the caller passes in.
     */
    #[Test]
    public function resolvesTheChangeAgainstTheBeforeImageRatherThanTheRepository(): void
    {
        $tripId = 'trip-alias';

        $before = new TripRequest();
        $before->sourceUrl = 'https://www.komoot.com/tour/123';
        $before->maxDistancePerDay = 80.0;

        $incoming = new TripRequest();
        $incoming->sourceUrl = 'https://www.komoot.com/tour/123';
        $incoming->maxDistancePerDay = 120.0;

        // Doctrine's aliasing, in one line: the repository serves the object the deserializer
        // populated, not the one the trip had a moment ago.
        $this->tripStateManager->method('getRequest')->willReturn($incoming);
        $this->computationTracker->method('getStatuses')->willReturn([]);

        $dispatched = [];
        $this->messageBus->method('dispatch')
            ->willReturnCallback(static function (object $msg) use (&$dispatched): Envelope {
                $dispatched[] = $msg;

                return new Envelope($msg);
            });

        $this->processor->process($incoming, new Patch(), ['id' => $tripId], ['previous_data' => $before]);

        $this->assertNotSame([], $dispatched, 'A pacing change must re-dispatch its computations; resolving against the repository compares the new settings with themselves and dispatches nothing.');
    }

    #[Test]
    public function doesNotDispatchWhenNothingChanged(): void
    {
        $tripId = 'trip-no-change';

        $request = new TripRequest();
        $request->sourceUrl = 'https://www.komoot.com/tour/123';

        $this->tripStateManager->method('getRequest')->willReturn($request);
        $this->computationTracker->method('getStatuses')->willReturn([]);


        $this->messageBus->expects($this->never())->method('dispatch');

        $this->processor->process($request, new Patch(), ['id' => $tripId], ['previous_data' => clone $request]);
    }

    #[Test]
    public function aNewSourceUrlIsThrottledOnTheTripCreationBudget(): void
    {
        $limiter = $this->limiter(1);
        $limiter->create($this->owner->getId()->toRfc4122())->consume();

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->owner);

        $processor = new TripUpdateProcessor(
            $this->messageBus,
            $this->tripStateManager,
            $this->computationTracker,
            new ParameterChangeResolver(),
            $this->createStub(TripGenerationTrackerInterface::class),
            $security,
            new TripLocker(new NativeClock()),
            new TripAnalysisDispatcher($this->messageBus, new EnrichmentMessageFactory()),
            $this->inertSupersession(),
            $limiter,
            new NativeClock(),
        );

        $before = new TripRequest();
        $before->sourceUrl = 'https://www.komoot.com/tour/123';

        $after = clone $before;
        $after->sourceUrl = 'https://www.komoot.com/tour/456';

        // Refused before anything is written or re-fetched.
        $this->tripStateManager->expects($this->never())->method('storeRequest');
        $this->messageBus->expects($this->never())->method('dispatch');

        $this->expectException(TooManyRequestsHttpException::class);

        $processor->process($after, new Patch(), ['id' => 'trip-refetch'], ['previous_data' => $before]);
    }

    #[Test]
    public function aSettingsEditDoesNotSpendTheRouteFetchBudget(): void
    {
        $limiter = $this->limiter(1);
        $limiter->create($this->owner->getId()->toRfc4122())->consume();

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->owner);
        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);
        $generationTracker->method('increment')->willReturn(2);

        $processor = new TripUpdateProcessor(
            $this->messageBus,
            $this->tripStateManager,
            $this->computationTracker,
            new ParameterChangeResolver(),
            $generationTracker,
            $security,
            new TripLocker(new NativeClock()),
            new TripAnalysisDispatcher($this->messageBus, new EnrichmentMessageFactory()),
            $this->inertSupersession(),
            $limiter,
            new NativeClock(),
        );

        $before = new TripRequest();
        $before->sourceUrl = 'https://www.komoot.com/tour/123';

        $after = clone $before;
        $after->fatigueFactor = 0.8;

        $this->tripStateManager->method('getRequest')->willReturn($after);
        $this->computationTracker->method('getStatuses')->willReturn([]);
        $this->messageBus->method('dispatch')->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $trip = $processor->process($after, new Patch(), ['id' => 'trip-settings'], ['previous_data' => $before]);

        self::assertSame('trip-settings', $trip->id);
    }

    private function limiter(int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'trip_create', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
    }

    /**
     * A real one, inert. It is `final readonly` so it cannot be doubled, and against a tracker
     * that knows no statuses it settles nothing. Its own behaviour is covered by
     * ComputationSupersessionTest.
     */
    private function inertSupersession(): ComputationSupersession
    {
        $publisher = $this->createStub(TripUpdatePublisherInterface::class);
        $tracker = $this->createStub(ComputationTrackerInterface::class);

        return new ComputationSupersession(
            $tracker,
            $publisher,
            new TripCompletionGate(
                $tracker,
                $publisher,
                $this->createStub(MessageBusInterface::class),
                $this->createStub(TripGenerationTrackerInterface::class),
            ),
            new NullLogger(),
        );
    }
}
