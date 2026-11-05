<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Entity\User;
use App\Enum\ComputationName;
use App\Enum\ComputationStatus;
use App\Repository\TripStageStoreInterface;
use App\Service\EnrichmentMessageFactory;
use App\Service\TripAnalysisDispatcher;
use App\State\AnalyzeTripProcessor;
use App\State\TripLocker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class AnalyzeTripProcessorTest extends TestCase
{
    private User $user;

    private int $dispatched = 0;

    #[\Override]
    protected function setUp(): void
    {
        $this->user = new User('analyze@example.com');
    }

    #[Test]
    public function aSettledTripIsAnalyzed(): void
    {
        $this->processor($this->statuses(ComputationStatus::DONE), $this->limiter(10))
            ->process($this->datedRequest(), new Post(), ['id' => 't']);

        self::assertGreaterThan(0, $this->dispatched);
    }

    #[Test]
    public function aQueuedAnalysisIsInFlight(): void
    {
        // resetComputation() leaves every re-armed computation `pending` until a worker picks
        // it up; a second call in that window used to queue the whole pipeline again.
        $this->expectException(ConflictHttpException::class);

        $this->processor($this->statuses(ComputationStatus::PENDING), $this->limiter(10))
            ->process($this->datedRequest(), new Post(), ['id' => 't']);
    }

    #[Test]
    public function aRunningAnalysisIsInFlight(): void
    {
        $this->expectException(ConflictHttpException::class);

        $statuses = $this->statuses(ComputationStatus::DONE);
        $statuses[ComputationName::POIS->value] = ComputationStatus::RUNNING->value;

        $this->processor($statuses, $this->limiter(10))->process($this->datedRequest(), new Post(), ['id' => 't']);
    }

    #[Test]
    public function computationsNeverDispatchedForADatelessTripDoNotLockTheEndpoint(): void
    {
        // Without a start date, weather/calendar/events are withheld, and wind/fords cascade
        // from weather: all five stay `pending` for good. They must not read as in flight.
        $statuses = $this->statuses(ComputationStatus::DONE);
        foreach ([ComputationName::WEATHER, ComputationName::CALENDAR, ComputationName::EVENTS, ComputationName::WIND, ComputationName::FORDS] as $withheld) {
            $statuses[$withheld->value] = ComputationStatus::PENDING->value;
        }

        $this->processor($statuses, $this->limiter(10))->process(new TripRequest(), new Post(), ['id' => 't']);

        self::assertGreaterThan(0, $this->dispatched);
    }

    #[Test]
    public function analyzeIsRateLimitedPerUser(): void
    {
        $limiter = $this->limiter(1);
        $limiter->create($this->user->getId()->toRfc4122())->consume();

        try {
            $this->processor($this->statuses(ComputationStatus::DONE), $limiter)
                ->process($this->datedRequest(), new Post(), ['id' => 'another-trip']);
            self::fail('The limiter should have refused the call.');
        } catch (TooManyRequestsHttpException $tooManyRequestsHttpException) {
            self::assertGreaterThan(0, $tooManyRequestsHttpException->getHeaders()['Retry-After'] ?? 0);
        }

        self::assertSame(0, $this->dispatched);
    }

    /**
     * @return array<string, string>
     */
    private function statuses(ComputationStatus $analysis): array
    {
        $statuses = [];
        foreach (ComputationName::pipeline() as $computation) {
            $statuses[$computation->value] = \in_array($computation, ComputationName::structuralPipeline(), true)
                ? ComputationStatus::DONE->value
                : $analysis->value;
        }

        return $statuses;
    }

    private function datedRequest(): TripRequest
    {
        $request = new TripRequest();
        $request->startDate = new \DateTimeImmutable('2026-06-01');

        return $request;
    }

    private function limiter(int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'trip_analyze', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
    }

    /**
     * @param array<string, string> $statuses
     */
    private function processor(array $statuses, RateLimiterFactory $limiter): AnalyzeTripProcessor
    {
        $coord = new Coordinate(lat: 45.0, lon: 5.0);
        $stageStore = $this->createStub(TripStageStoreInterface::class);
        $stageStore->method('getStages')->willReturn([
            new Stage(tripId: 't', dayNumber: 1, distance: 80.0, elevation: 500.0, startPoint: $coord, endPoint: $coord),
            new Stage(tripId: 't', dayNumber: 2, distance: 90.0, elevation: 600.0, startPoint: $coord, endPoint: $coord),
        ]);

        $tracker = $this->createStub(ComputationTrackerInterface::class);
        $tracker->method('getStatuses')->willReturn($statuses);

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(function (object $m): Envelope {
            ++$this->dispatched;

            return new Envelope($m);
        });

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->user);

        return new AnalyzeTripProcessor(
            $stageStore,
            $tracker,
            $this->createStub(TripGenerationTrackerInterface::class),
            new TripAnalysisDispatcher($messageBus, new EnrichmentMessageFactory()),
            new TripLocker(new NativeClock()),
            $security,
            $limiter,
            new NativeClock(),
        );
    }
}
