<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\HourlyWeatherSlot;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage;
use App\ComputationTracker\ComputationTrackerInterface;
use App\ComputationTracker\TripGenerationTrackerInterface;
use App\Mercure\MercureEventType;
use App\Mercure\TripUpdatePublisherInterface;
use App\Message\AnalyzeWind;
use App\MessageHandler\AnalyzeWindHandler;
use App\Repository\TripRequestRepositoryInterface;
use App\Tests\Unit\AlertMessageTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class AnalyzeWindHandlerTest extends TestCase
{
    use AlertMessageTestTrait;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function renderedMessageProvider(): iterable
    {
        yield 'french' => ['fr', 'Vents de face attendus sur cette étape (≥25 km/h). Prévois du temps supplémentaire.'];
        yield 'english' => ['en', 'Headwinds expected on this stage (≥25 km/h). Allow extra time.'];
    }

    /**
     * The threshold is a `>=` comparison: a stage at exactly 25 km/h counts, so
     * the message must read the constant and say "≥", not ">".
     */
    #[DataProvider('renderedMessageProvider')]
    #[Test]
    public function renderedMessageMatchesTheHeadwindCondition(string $locale, string $expected): void
    {
        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([
            $this->createStage('trip-1', 1, $this->createWeather(windSpeed: 25.0, relativeWind: WeatherForecast::RELATIVE_WIND_HEADWIND)),
            $this->createStage('trip-1', 2, $this->createWeather(windSpeed: 30.0, relativeWind: WeatherForecast::RELATIVE_WIND_HEADWIND)),
        ]);
        $tripStateManager->method('getLocale')->willReturn($locale);

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::WIND_ALERTS,
                $this->callback(static function (array $data) use ($expected): bool {
                    self::assertSame($expected, $data['alerts'][0]['message']);

                    return true;
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher);
        $handler(new AnalyzeWind('trip-1'));
    }

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
        ?TripGenerationTrackerInterface $generationTracker = null,
    ): AnalyzeWindHandler {
        $computationTracker = $this->createStub(ComputationTrackerInterface::class);
        $computationTracker->method('getProgress')->willReturn(['completed' => 0, 'failed' => 0, 'settled' => 0, 'total' => 1]);

        $stubTranslator = $this->createStub(TranslatorInterface::class);
        $stubTranslator->method('trans')->willReturnCallback(
            static fn (string $id, array $params): string => match ($id) {
                'alert.wind.warning' => \sprintf('Headwind on %d/%d stages', $params['%count%'], $params['%total%']),
                'alert.comfort.warning' => \sprintf('Poor comfort on %d/%d stages', $params['%count%'], $params['%total%']),
                default => $id,
            },
        );

        return new AnalyzeWindHandler(
            $computationTracker,
            $publisher,
            $generationTracker ?? $this->createStub(TripGenerationTrackerInterface::class),
            new NullLogger(),
            $tripStateManager,
            $this->createStub(MessageBusInterface::class),
            $this->createAlertRenderer(),
        );
    }

    #[Test]
    public function emitsHeatColdRainAndGustAlertsFromHourlyDerivedFields(): void
    {
        $extreme = new WeatherForecast(
            icon: 'sunny',
            description: 'Clear',
            tempMin: 20.0,
            tempMax: 35.0,
            windSpeed: 15.0,
            windDirection: 'N',
            precipitationProbability: 80,
            humidity: 60,
            comfortIndex: 80,
            relativeWindDirection: WeatherForecast::RELATIVE_WIND_CROSSWIND,
            apparentTempMin: -1.0,
            apparentTempMax: 34.0,
            windGusts: 55.0,
            precipitationMm: 12.0,
            hourly: [new HourlyWeatherSlot(9, 20.0, 19.0, 12.0, 80, 15.0, 55.0, 0, WeatherForecast::RELATIVE_WIND_CROSSWIND, 61)],
        );

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$this->createStage('trip-1', 1, $extreme)]);
        $tripStateManager->method('getLocale')->willReturn('en');

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::WIND_ALERTS,
                $this->callback(static function (array $data): bool {
                    $codes = array_column($data['alerts'], 'code');
                    self::assertContains('heat_extreme', $codes);
                    self::assertContains('cold_extreme', $codes);
                    self::assertContains('rain_heavy', $codes);
                    self::assertContains('wind_gusts_strong', $codes);

                    return true;
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher);
        $handler(new AnalyzeWind('trip-1'));
    }

    #[Test]
    public function noExtremeAlertsWhenForecastHasNoHourlyData(): void
    {
        // A legacy forecast without hourly data must not trip the extreme thresholds
        // on its default field values (apparentTempMin defaults to 0.0).
        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$this->createStage('trip-1', 1, $this->createWeather())]);
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
        $poor = $this->createStage('trip-1', 1, $this->createWeather(comfortIndex: 30));
        $fine = $this->createStage('trip-1', 2, $this->createWeather(comfortIndex: 80));
        $alsoPoor = $this->createStage('trip-1', 3, $this->createWeather(comfortIndex: 20));

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$poor, $fine, $alsoPoor]);
        $tripStateManager->method('getLocale')->willReturn('en');

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::WIND_ALERTS,
                // One alert per affected stage, each naming the stage it describes —
                // the comfortable one gets none.
                $this->callback(static function (array $data) use ($poor, $fine, $alsoPoor): bool {
                    $alerts = $data['alerts'];

                    return 2 === \count($alerts)
                        && [$poor->id, $alsoPoor->id] === array_column($alerts, 'stageId')
                        && !\in_array($fine->id, array_column($alerts, 'stageId'), true)
                        && 'warning' === $alerts[0]['type']
                        && 'comfort_poor_conditions' === $alerts[0]['code']
                        && \is_array($alerts[0]['action'])
                        && 'dismiss' === $alerts[0]['action']['kind'];
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher);
        $handler(new AnalyzeWind('trip-1'));
    }

    #[Test]
    public function windAlertWhenHeadwindRatioExceeded(): void
    {
        $windy = $this->createStage('trip-1', 1, $this->createWeather(windSpeed: 30.0, relativeWind: WeatherForecast::RELATIVE_WIND_HEADWIND));
        $alsoWindy = $this->createStage('trip-1', 2, $this->createWeather(windSpeed: 28.0, relativeWind: WeatherForecast::RELATIVE_WIND_HEADWIND));
        $calm = $this->createStage('trip-1', 3, $this->createWeather(windSpeed: 5.0, relativeWind: WeatherForecast::RELATIVE_WIND_TAILWIND));

        $tripStateManager = $this->createStub(TripRequestRepositoryInterface::class);
        $tripStateManager->method('getStages')->willReturn([$windy, $alsoWindy, $calm]);
        $tripStateManager->method('getLocale')->willReturn('en');

        $publisher = $this->createMock(TripUpdatePublisherInterface::class);
        $publisher->expects($this->once())
            ->method('publish')
            ->with(
                'trip-1',
                MercureEventType::WIND_ALERTS,
                // The ratio still decides whether anything is raised at all; what changed
                // is that the alerts land on the stages carrying the headwind.
                $this->callback(static function (array $data) use ($windy, $alsoWindy, $calm): bool {
                    $alerts = $data['alerts'];

                    return 2 === \count($alerts)
                        && [$windy->id, $alsoWindy->id] === array_column($alerts, 'stageId')
                        && !\in_array($calm->id, array_column($alerts, 'stageId'), true)
                        && 'warning' === $alerts[0]['type']
                        && 'wind_headwind' === $alerts[0]['code']
                        && \is_array($alerts[0]['action'])
                        && 'dismiss' === $alerts[0]['action']['kind'];
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
                // Two stages, each carrying both a headwind and poor comfort: four alerts,
                // two per stage, where the aggregated form produced one of each.
                $this->callback(static fn (array $data): bool => 4 === \count($data['alerts'])
                    && ['wind_headwind', 'wind_headwind', 'comfort_poor_conditions', 'comfort_poor_conditions'] === array_column($data['alerts'], 'code')),
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
                        && 'comfort_poor_conditions' === $alerts[0]['code'];
                }),
            );

        $handler = $this->createHandler($tripStateManager, $publisher);
        $handler(new AnalyzeWind('trip-1'));
    }
}
