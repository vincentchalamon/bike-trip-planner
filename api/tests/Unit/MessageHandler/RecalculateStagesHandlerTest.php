<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ApiResource\TripRequest;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\RecalculateStages;
use App\Message\ScanAccommodations;
use App\MessageHandler\RecalculateStagesHandler;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RecalculateStagesHandlerTest extends TestCase
{
    private function createHandler(
        TripRequestRepositoryInterface $tripStateManager,
        TripUpdatePublisherInterface $publisher,
        MessageBusInterface $messageBus,
        ?TripGenerationTrackerInterface $generationTracker = null,
    ): RecalculateStagesHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('isAllComplete')->willReturn(false);
        $computationTracker->method('areAllEnrichmentsCompleted')->willReturn(false);
        $computationTracker->method('getProgress')->willReturn(['completed' => 0, 'failed' => 0, 'total' => 1]);

        return new RecalculateStagesHandler(
            $computationTracker,
            $publisher,
            $generationTracker ?? $this->createStub(TripGenerationTrackerInterface::class),
            new NullLogger(),
            $tripStateManager,
            $messageBus,
        );
    }

    #[Test]
    public function stagesComputedPayloadIncludesGeometry(): void
    {
        $coordinate = new Coordinate(48.8566, 2.3522, 35.0);

        $stage = new Stage(
            tripId: 'trip-1',
            dayNumber: 1,
            distance: 80.0,
            elevation: 500.0,
            startPoint: $coordinate,
            endPoint: new Coordinate(49.0, 2.5, 50.0),
            geometry: [$coordinate],
        );

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$stage]);

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::STAGES_COMPUTED,
                $this->callback(static function (array $data): bool {
                    $geometry = $data['stages'][0]['geometry'] ?? null;

                    return \is_array($geometry)
                        && 1 === \count($geometry)
                        && \is_array($geometry[0])
                        && 48.8566 === $geometry[0]['lat']
                        && 2.3522 === $geometry[0]['lon']
                        && 35.0 === $geometry[0]['ele'];
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $messageBus);

        $handler(new RecalculateStages(tripId: 'trip-1', affectedIndices: [], skipGeographicScans: true));
    }

    #[Test]
    public function noStagesReturnsEarly(): void
    {
        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn(null);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $handler = $this->createHandler(
            $tripStateManager,
            $publisher,
            $this->createStub(MessageBusInterface::class),
        );

        $handler(new RecalculateStages(tripId: 'trip-1', affectedIndices: []));
    }

    #[Test]
    public function staleMessageIsDiscardedWithoutProcessing(): void
    {
        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $generationTracker = $this->createStub(TripGenerationTrackerInterface::class);
        $generationTracker->method('current')->willReturn(5);

        $handler = $this->createHandler($tripStateManager, $publisher, $messageBus, $generationTracker);

        $handler(new RecalculateStages('trip-1', [], generation: 3));
    }

    #[Test]
    public function stagesComputedDispatchesScanAccommodationsPerAffectedIndex(): void
    {
        $makeStage = static fn (int $day): Stage => new Stage(
            tripId: 'trip-1',
            dayNumber: $day,
            distance: 80.0,
            elevation: 500.0,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate(48.5, 2.5),
        );

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$makeStage(1), $makeStage(2), $makeStage(3)]);

        $request = new TripRequest();
        $tripStateManager->method('getRequest')->willReturn($request);

        $publisher = $this->createStub(TripUpdatePublisherInterface::class);

        /** @var list<object> $dispatched */
        $dispatched = [];
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched[] = $message;

                return new Envelope($message);
            }
        );

        $handler = $this->createHandler($tripStateManager, $publisher, $messageBus);

        $handler(new RecalculateStages(tripId: 'trip-1', affectedIndices: [0, 2]));

        $scanMessages = array_values(array_filter(
            $dispatched,
            static fn (object $m): bool => $m instanceof ScanAccommodations,
        ));

        $this->assertCount(2, $scanMessages);

        /** @var ScanAccommodations $first */
        $first = $scanMessages[0];
        $this->assertSame(0, $first->stageIndex);
        $this->assertFalse($first->isExpandScan);

        /** @var ScanAccommodations $second */
        $second = $scanMessages[1];
        $this->assertSame(2, $second->stageIndex);
        $this->assertFalse($second->isExpandScan);
    }
}
