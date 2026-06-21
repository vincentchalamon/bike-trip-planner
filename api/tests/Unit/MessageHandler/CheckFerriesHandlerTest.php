<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckFerries;
use App\MessageHandler\CheckFerriesHandler;
use App\Osm\FerryRepositoryInterface;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CheckFerriesHandlerTest extends TestCase
{
    private function createHandler(
        TripRequestRepositoryInterface $tripStateManager,
        TripUpdatePublisherInterface $publisher,
        FerryRepositoryInterface $ferryRepository,
    ): CheckFerriesHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('getProgress')->willReturn(['completed' => 0, 'failed' => 0, 'total' => 1]);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params): string => match ($id) {
                'alert.ferry.warning' => \sprintf('Stage %s takes a ferry.', $params['%stage%']),
                default => $id,
            },
        );

        return new CheckFerriesHandler(
            $computationTracker,
            $publisher,
            $this->createStub(TripGenerationTrackerInterface::class),
            new NullLogger(),
            $tripStateManager,
            $ferryRepository,
            $translator,
            $this->createStub(MessageBusInterface::class),
        );
    }

    /** @param list<Stage>|null $stages */
    private function createTripStateManager(?array $stages): TripRequestRepositoryInterface
    {
        $manager = $this->createStub(TripRequestRepositoryInterface::class);
        $manager->method('getStages')->willReturn($stages);
        $manager->method('getLocale')->willReturn('en');

        return $manager;
    }

    private function stage(int $day, bool $isRestDay = false): Stage
    {
        return new Stage(
            tripId: 'trip-1',
            dayNumber: $day,
            distance: 60.0,
            elevation: 100.0,
            startPoint: new Coordinate(47.0, -2.0),
            endPoint: new Coordinate(47.1, -2.1),
            geometry: [new Coordinate(47.0, -2.0), new Coordinate(47.1, -2.1)],
            isRestDay: $isRestDay,
        );
    }

    /**
     * @param array<int, list<array{name: ?string, lat: float, lon: float}>> $byStageCall ferries returned per findNearStage call, in order
     */
    private function ferryRepository(array $byStageCall): FerryRepositoryInterface
    {
        $repository = $this->createStub(FerryRepositoryInterface::class);
        $index = 0;
        $repository->method('findNearStage')->willReturnCallback(
            static function () use ($byStageCall, &$index): array {
                return $byStageCall[$index++] ?? [];
            },
        );

        return $repository;
    }

    #[Test]
    public function emitsWarningForAStageTakingAFerry(): void
    {
        $tripStateManager = $this->createTripStateManager([$this->stage(1), $this->stage(2)]);
        // Stage 0: a ferry; stage 1: none.
        $ferryRepository = $this->ferryRepository([
            [['name' => 'Le Passage du Gois', 'lat' => 47.05, 'lon' => -2.05]],
            [],
        ]);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::FERRY_ALERTS,
                $this->callback(static function (array $data): bool {
                    $alerts = $data['alerts'];

                    return 1 === \count($alerts)
                        && 'warning' === $alerts[0]['type']
                        && 0 === $alerts[0]['stageIndex']
                        && str_contains((string) $alerts[0]['message'], 'ferry')
                        && abs($alerts[0]['lat'] - 47.05) < 0.001
                        && abs($alerts[0]['lon'] - (-2.05)) < 0.001
                        && 'navigate' === $alerts[0]['action']['kind']
                        && ['lat' => 47.05, 'lon' => -2.05] === $alerts[0]['action']['payload'];
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $ferryRepository);
        $handler(new CheckFerries('trip-1'));
    }

    #[Test]
    public function deduplicatesTheSameFerryWithinAStage(): void
    {
        $tripStateManager = $this->createTripStateManager([$this->stage(1)]);
        $ferryRepository = $this->ferryRepository([
            [
                ['name' => 'Bac de X', 'lat' => 47.05, 'lon' => -2.05],
                ['name' => 'Bac de X', 'lat' => 47.06, 'lon' => -2.06],
            ],
        ]);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::FERRY_ALERTS,
                $this->callback(static fn (array $data): bool => 1 === \count($data['alerts'])),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $ferryRepository);
        $handler(new CheckFerries('trip-1'));
    }

    #[Test]
    public function skipsRestDaysAndEmitsNoAlertWhenNoFerry(): void
    {
        $tripStateManager = $this->createTripStateManager([$this->stage(1, isRestDay: true)]);

        $ferryRepository = $this->createMock(FerryRepositoryInterface::class);
        $ferryRepository->expects($this->never())->method('findNearStage');

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::FERRY_ALERTS,
                $this->callback(static fn (array $data): bool => [] === $data['alerts']),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $ferryRepository);
        $handler(new CheckFerries('trip-1'));
    }

    #[Test]
    public function nullStagesYieldsNoPublish(): void
    {
        $tripStateManager = $this->createTripStateManager(null);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $handler = $this->createHandler($tripStateManager, $publisher, $this->createStub(FerryRepositoryInterface::class));
        $handler(new CheckFerries('trip-1'));
    }
}
