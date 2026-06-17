<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\CheckFords;
use App\MessageHandler\CheckFordsHandler;
use App\Osm\FordRepositoryInterface;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CheckFordsHandlerTest extends TestCase
{
    private function createHandler(
        TripRequestRepositoryInterface $tripStateManager,
        TripUpdatePublisherInterface $publisher,
        FordRepositoryInterface $fordRepository,
    ): CheckFordsHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('getProgress')->willReturn(['completed' => 0, 'failed' => 0, 'total' => 1]);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => $id,
        );

        return new CheckFordsHandler(
            $computationTracker,
            $publisher,
            $this->createStub(TripGenerationTrackerInterface::class),
            new NullLogger(),
            $tripStateManager,
            $fordRepository,
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

    private function stage(int $day, ?int $precipitationProbability = null, bool $isRestDay = false): Stage
    {
        $stage = new Stage(
            tripId: 'trip-1',
            dayNumber: $day,
            distance: 60.0,
            elevation: 100.0,
            startPoint: new Coordinate(47.0, -2.0),
            endPoint: new Coordinate(47.1, -2.1),
            geometry: [new Coordinate(47.0, -2.0), new Coordinate(47.1, -2.1)],
            isRestDay: $isRestDay,
        );

        if (null !== $precipitationProbability) {
            $stage->weather = new WeatherForecast(
                icon: 'rain',
                description: 'Rain',
                tempMin: 10.0,
                tempMax: 18.0,
                windSpeed: 12.0,
                windDirection: 'N',
                precipitationProbability: $precipitationProbability,
                humidity: 70,
                comfortIndex: 80,
                relativeWindDirection: WeatherForecast::RELATIVE_WIND_UNKNOWN,
            );
        }

        return $stage;
    }

    private function fordRepository(): FordRepositoryInterface
    {
        $repository = $this->createStub(FordRepositoryInterface::class);
        $repository->method('findNearStage')->willReturn([
            ['name' => 'Gué du Moulin', 'lat' => 47.05, 'lon' => -2.05],
        ]);

        return $repository;
    }

    #[Test]
    public function emitsANudgeInDryWeather(): void
    {
        $tripStateManager = $this->createTripStateManager([$this->stage(1, precipitationProbability: 10)]);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::FORD_ALERTS,
                $this->callback(static function (array $data): bool {
                    $alerts = $data['alerts'];

                    return 1 === \count($alerts)
                        && 'nudge' === $alerts[0]['type']
                        && 'alert.ford.nudge' === $alerts[0]['message']
                        && 'navigate' === $alerts[0]['action']['kind'];
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $this->fordRepository());
        $handler(new CheckFords('trip-1'));
    }

    #[Test]
    public function escalatesToAWarningWhenRainIsForecast(): void
    {
        $tripStateManager = $this->createTripStateManager([$this->stage(1, precipitationProbability: 80)]);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::FORD_ALERTS,
                $this->callback(static function (array $data): bool {
                    $alerts = $data['alerts'];

                    return 1 === \count($alerts)
                        && 'warning' === $alerts[0]['type']
                        && 'alert.ford.warning' === $alerts[0]['message'];
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $this->fordRepository());
        $handler(new CheckFords('trip-1'));
    }

    #[Test]
    public function treatsMissingForecastAsDry(): void
    {
        // No weather on the stage → no rain info → nudge, not warning.
        $tripStateManager = $this->createTripStateManager([$this->stage(1)]);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::FORD_ALERTS,
                $this->callback(static fn (array $data): bool => 'nudge' === $data['alerts'][0]['type']),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $this->fordRepository());
        $handler(new CheckFords('trip-1'));
    }

    #[Test]
    public function skipsRestDays(): void
    {
        $tripStateManager = $this->createTripStateManager([$this->stage(1, isRestDay: true)]);

        $fordRepository = $this->createMock(FordRepositoryInterface::class);
        $fordRepository->expects($this->never())->method('findNearStage');

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::FORD_ALERTS,
                $this->callback(static fn (array $data): bool => [] === $data['alerts']),
            );

        $handler = $this->createHandler($tripStateManager, $publisher, $fordRepository);
        $handler(new CheckFords('trip-1'));
    }

    #[Test]
    public function nullStagesYieldsNoPublish(): void
    {
        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $handler = $this->createHandler($this->createTripStateManager(null), $publisher, $this->createStub(FordRepositoryInterface::class));
        $handler(new CheckFords('trip-1'));
    }
}
