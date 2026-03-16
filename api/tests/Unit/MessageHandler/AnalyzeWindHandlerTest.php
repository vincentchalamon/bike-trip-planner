<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\AnalyzeWind;
use App\MessageHandler\AnalyzeWindHandler;
use App\Repository\TripRequestRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AnalyzeWindHandlerTest extends TestCase
{
    private function createWeather(
        float $windSpeed = 10.0,
        string $relativeWind = WeatherForecast::RELATIVE_WIND_CROSSWIND,
        int $comfortIndex = 80,
    ): WeatherForecast {
        return new WeatherForecast(
            icon: 'sunny',
            description: 'Clear',
            tempMin: 10.0,
            tempMax: 25.0,
            windSpeed: $windSpeed,
            windDirection: 'N',
            precipitationProbability: 10,
            humidity: 60,
            comfortIndex: $comfortIndex,
            relativeWindDirection: $relativeWind,
        );
    }

    private function createStage(string $tripId, int $dayNumber, ?WeatherForecast $weather = null): Stage
    {
        $stage = new Stage(
            tripId: $tripId,
            dayNumber: $dayNumber,
            distance: 80000.0,
            elevation: 500.0,
            startPoint: new Coordinate(48.0, 2.0),
            endPoint: new Coordinate(48.1, 2.1),
        );
        $stage->weather = $weather;

        return $stage;
    }

    private function createHandler(
        TripRequestRepositoryInterface $tripStateManager,
        TripUpdatePublisherInterface $publisher,
    ): AnalyzeWindHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('isAllComplete')->willReturn(false);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params): string => match ($id) {
                'alert.wind.warning' => \sprintf('Headwind on %d/%d stages', $params['%count%'], $params['%total%']),
                'alert.comfort.warning' => \sprintf('Poor comfort on %d/%d stages', $params['%count%'], $params['%total%']),
                default => $id,
            },
        );

        return new AnalyzeWindHandler(
            $computationTracker,
            $publisher,
            $tripStateManager,
            $translator,
        );
    }

    #[Test]
    public function noComfortAlertWhenAllStagesHaveGoodComfort(): void
    {
        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([
            $this->createStage('trip-1', 1, $this->createWeather(comfortIndex: 80)),
            $this->createStage('trip-1', 2, $this->createWeather(comfortIndex: 60)),
        ]);
        $tripStateManager->method('getLocale')->willReturn('en');

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::WIND_ALERTS,
                $this->callback(static fn (array $data): bool => [] === $data['alerts']),
            );

        $handler = $this->createHandler($tripStateManager, $publisher);
        $handler(new AnalyzeWind('trip-1'));
    }

    #[Test]
    public function comfortAlertWhenPoorComfortStagesExist(): void
    {
        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([
            $this->createStage('trip-1', 1, $this->createWeather(comfortIndex: 30)),
            $this->createStage('trip-1', 2, $this->createWeather(comfortIndex: 80)),
            $this->createStage('trip-1', 3, $this->createWeather(comfortIndex: 20)),
        ]);
        $tripStateManager->method('getLocale')->willReturn('en');

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::WIND_ALERTS,
                $this->callback(static function (array $data): bool {
                    $alerts = $data['alerts'];

                    return 1 === \count($alerts)
                        && 'warning' === $alerts[0]['type']
                        && str_contains((string) $alerts[0]['message'], 'Poor comfort on 2/3');
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher);
        $handler(new AnalyzeWind('trip-1'));
    }

    #[Test]
    public function windAlertWhenHeadwindRatioExceeded(): void
    {
        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([
            $this->createStage('trip-1', 1, $this->createWeather(windSpeed: 30.0, relativeWind: WeatherForecast::RELATIVE_WIND_HEADWIND)),
            $this->createStage('trip-1', 2, $this->createWeather(windSpeed: 28.0, relativeWind: WeatherForecast::RELATIVE_WIND_HEADWIND)),
            $this->createStage('trip-1', 3, $this->createWeather(windSpeed: 5.0, relativeWind: WeatherForecast::RELATIVE_WIND_TAILWIND)),
        ]);
        $tripStateManager->method('getLocale')->willReturn('en');

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::WIND_ALERTS,
                $this->callback(static function (array $data): bool {
                    $alerts = $data['alerts'];

                    return 1 === \count($alerts)
                        && 'warning' === $alerts[0]['type']
                        && str_contains((string) $alerts[0]['message'], 'Headwind on 2/3');
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher);
        $handler(new AnalyzeWind('trip-1'));
    }

    #[Test]
    public function bothAlertsWhenHeadwindAndPoorComfort(): void
    {
        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([
            $this->createStage('trip-1', 1, $this->createWeather(windSpeed: 30.0, relativeWind: WeatherForecast::RELATIVE_WIND_HEADWIND, comfortIndex: 20)),
            $this->createStage('trip-1', 2, $this->createWeather(windSpeed: 28.0, relativeWind: WeatherForecast::RELATIVE_WIND_HEADWIND, comfortIndex: 15)),
        ]);
        $tripStateManager->method('getLocale')->willReturn('en');

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::WIND_ALERTS,
                $this->callback(static fn (array $data): bool => 2 === \count($data['alerts'])),
            );

        $handler = $this->createHandler($tripStateManager, $publisher);
        $handler(new AnalyzeWind('trip-1'));
    }

    #[Test]
    public function noAlertWhenNoStagesHaveWeather(): void
    {
        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([
            $this->createStage('trip-1', 1),
            $this->createStage('trip-1', 2),
        ]);
        $tripStateManager->method('getLocale')->willReturn('en');

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::WIND_ALERTS,
                $this->callback(static fn (array $data): bool => [] === $data['alerts']),
            );

        $handler = $this->createHandler($tripStateManager, $publisher);
        $handler(new AnalyzeWind('trip-1'));
    }

    #[Test]
    public function comfortAlertBoundary(): void
    {
        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([
            $this->createStage('trip-1', 1, $this->createWeather(comfortIndex: 40)), // exactly at yellow/red boundary → no alert
            $this->createStage('trip-1', 2, $this->createWeather(comfortIndex: 39)), // one below threshold → alert fires
        ]);
        $tripStateManager->method('getLocale')->willReturn('en');

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::WIND_ALERTS,
                $this->callback(static function (array $data): bool {
                    $alerts = $data['alerts'];

                    return 1 === \count($alerts)
                        && str_contains((string) $alerts[0]['message'], 'Poor comfort on 1/2');
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher);
        $handler(new AnalyzeWind('trip-1'));
    }
}
