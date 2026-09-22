<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use App\Service\EnrichmentMessageFactory;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripBatchRecomputeRequest;
use App\ApiResource\TripModification;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Message\RecalculateStages;
use App\Message\ScanPois;
use App\Repository\TripRequestRepositoryInterface;
use App\Service\ComputationDependencyResolver;
use App\Service\TripAnalysisDispatcher;
use App\State\TripBatchRecomputeProcessor;
use App\Concurrency\IfMatch;
use Symfony\Component\HttpFoundation\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class TripBatchRecomputeProcessorTest extends TestCase
{
    #[Test]
    public function recomputeIsRateLimited(): void
    {
        // SEC-010: once the per-trip recompute limiter is exhausted, the endpoint
        // must reject with 429 instead of re-dispatching the enrichment pipeline.
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $limiter = new RateLimiterFactory(
            ['id' => 'trip_recompute', 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
        // Exhaust the single token for this trip so the processor's own consume() fails.
        $limiter->create('t')->consume();

        $processor = new TripBatchRecomputeProcessor(
            $this->createStub(TripRequestRepositoryInterface::class),
            $this->createStub(TripGenerationTrackerInterface::class),
            new ComputationDependencyResolver(new EnrichmentMessageFactory()),
            $messageBus,
            $this->createStub(ComputationTrackerInterface::class),
            new TripAnalysisDispatcher($messageBus, new EnrichmentMessageFactory()),
            $limiter,
        );

        $this->expectException(TooManyRequestsHttpException::class);
        $processor->process(
            new TripBatchRecomputeRequest([new TripModification(type: 'pacing')]),
            new Post(),
            ['id' => 't'],
        );
    }

    /**
     * Runs a `pacing` recompute with the given tracker progress and returns the
     * dispatched message class names.
     *
     * @param array{completed: int, failed: int, settled: int, total: int} $progress
     *
     * @return list<class-string>
     */
    private function recomputeWithProgress(array $progress): array
    {
        $coord = new Coordinate(lat: 45.0, lon: 5.0);
        $stages = [
            new Stage(tripId: 't', dayNumber: 1, distance: 80.0, elevation: 500.0, startPoint: $coord, endPoint: $coord),
            new Stage(tripId: 't', dayNumber: 2, distance: 90.0, elevation: 600.0, startPoint: $coord, endPoint: $coord),
        ];

        $dispatched = [];
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message::class;

                return new Envelope($message);
            },
        );

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn($stages);
        $tripStateManager->method('getRequest')->willReturn(new TripRequest());

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);
        $generationTracker->method('increment')->willReturn(2);

        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('getProgress')->willReturn($progress);

        $processor = new TripBatchRecomputeProcessor(
            $tripStateManager,
            $generationTracker,
            new ComputationDependencyResolver(new EnrichmentMessageFactory()),
            $messageBus,
            $computationTracker,
            new TripAnalysisDispatcher($messageBus, new EnrichmentMessageFactory()),
            new RateLimiterFactory(['id' => 'trip_recompute_test', 'policy' => 'no_limit'], new InMemoryStorage()),
        );

        $request = new TripBatchRecomputeRequest([
            new TripModification(type: 'pacing'),
        ]);

        $processor->process($request, new Post(), ['id' => 't']);

        return $dispatched;
    }

    /**
     * A processor wired to the given generation tracker, with everything else stubbed flat.
     */
    private function recomputeProcessor(TripGenerationTrackerInterface $generationTracker): TripBatchRecomputeProcessor
    {
        $coord = new Coordinate(lat: 45.0, lon: 5.0);

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([
            new Stage(tripId: 't', dayNumber: 1, distance: 80.0, elevation: 500.0, startPoint: $coord, endPoint: $coord),
        ]);
        $tripStateManager->method('getRequest')->willReturn(new TripRequest());

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(
            static fn (object $message): Envelope => new Envelope($message),
        );

        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('getProgress')->willReturn(['completed' => 16, 'failed' => 0, 'settled' => 16, 'total' => 16]);

        return new TripBatchRecomputeProcessor(
            $tripStateManager,
            $generationTracker,
            new ComputationDependencyResolver(new EnrichmentMessageFactory()),
            $messageBus,
            $computationTracker,
            new TripAnalysisDispatcher($messageBus, new EnrichmentMessageFactory()),
            new RateLimiterFactory(['id' => 'trip_recompute_test', 'policy' => 'no_limit'], new InMemoryStorage()),
        );
    }

    /**
     * The batch carries the client's `If-Match` into the version bump.
     *
     * The comparison itself happens inside the repository, under the write lock, so what is
     * checkable here is the wiring — and the wiring is what would silently rot: the helper
     * above stubs `increment()` to answer the same value whatever it is handed, so a dropped
     * or miswired precondition fails nothing (#1292 review). Meanwhile the functional test
     * for this endpoint is answered by the fail-fast check in the processor decorator and
     * never reaches this call at all.
     */
    #[Test]
    public function carriesTheClientPreconditionIntoTheVersionBump(): void
    {
        $generationTracker = $this->createMock(TripGenerationTrackerInterface::class);
        $generationTracker->expects(self::once())
            ->method('increment')
            ->with('t', 7)
            ->willReturn(8);

        $request = new Request();
        $request->headers->set(IfMatch::HEADER, '"7"');

        $this->recomputeProcessor($generationTracker)->process(
            new TripBatchRecomputeRequest([new TripModification(type: 'pacing')]),
            new Post(),
            ['id' => 't'],
            ['request' => $request],
        );
    }

    #[Test]
    public function inFlightAnalysisReRunsTheFullPipeline(): void
    {
        // Some computations are still pending: a minimal recompute would strand
        // the in-flight ones (the generation bump discards them), so the full
        // enrichment pipeline must be re-dispatched instead (recette #649).
        $dispatched = $this->recomputeWithProgress(['completed' => 7, 'failed' => 0, 'settled' => 7, 'total' => 16]);

        self::assertContains(ScanPois::class, $dispatched, 'Full pipeline must run while the analysis is in flight.');
        self::assertNotContains(RecalculateStages::class, $dispatched, 'Minimal resolver path must be skipped while in flight.');
    }

    #[Test]
    public function settledAnalysisUsesTheMinimalResolver(): void
    {
        // Every computation has settled: the minimal, dependency-resolved
        // recompute is enough (and avoids re-running the whole pipeline).
        $dispatched = $this->recomputeWithProgress(['completed' => 16, 'failed' => 0, 'settled' => 16, 'total' => 16]);

        self::assertContains(RecalculateStages::class, $dispatched, 'Settled trip uses the minimal resolver (RecalculateStages for pacing).');
        self::assertNotContains(ScanPois::class, $dispatched, 'Full pipeline must not run for a settled trip.');
    }

    #[Test]
    public function uninitializedTrackerFallsBackToMinimalResolver(): void
    {
        // getProgress() returns total=0 before initializeComputations() is called —
        // the guard must treat this the same as "settled" and fall through to the
        // minimal resolver, not the full pipeline.
        $dispatched = $this->recomputeWithProgress(['completed' => 0, 'failed' => 0, 'settled' => 0, 'total' => 0]);

        self::assertContains(RecalculateStages::class, $dispatched, 'Uninitialized tracker must use the minimal resolver.');
        self::assertNotContains(ScanPois::class, $dispatched, 'Full pipeline must not run when total=0.');
    }
}
