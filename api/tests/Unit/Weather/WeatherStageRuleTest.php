<?php

declare(strict_types=1);

namespace App\Tests\Unit\Weather;

use App\ApiResource\Model\Coordinate;
use App\ApiResource\Model\HourlyWeatherSlot;
use App\ApiResource\Model\WeatherForecast;
use App\ApiResource\Stage;
use App\Enum\AlertCode;
use App\Weather\WeatherStageRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WeatherStageRuleTest extends TestCase
{
    #[Test]
    public function eachRuleOwnsOneWeatherCode(): void
    {
        $codes = array_map(static fn (WeatherStageRule $rule): AlertCode => $rule->code(), WeatherStageRule::cases());

        self::assertSame([
            AlertCode::WIND_HEADWIND,
            AlertCode::COMFORT_POOR_CONDITIONS,
            AlertCode::HEAT_EXTREME,
            AlertCode::COLD_EXTREME,
            AlertCode::RAIN_HEAVY,
            AlertCode::WIND_GUSTS_STRONG,
        ], $codes);
    }

    #[Test]
    public function headwindStagesAreFlaggedOnlyWhenTheyMakeUpSixtyPercentOfTheForecastOnes(): void
    {
        $headwind = fn (int $day): Stage => $this->stage($day, $this->forecast(windSpeed: 30.0, relativeWind: WeatherForecast::RELATIVE_WIND_HEADWIND));
        $calm = fn (int $day): Stage => $this->stage($day, $this->forecast());

        $threeOfFive = [$headwind(1), $headwind(2), $headwind(3), $calm(4), $calm(5), $this->stage(6, null)];
        $twoOfFive = [$headwind(1), $headwind(2), $calm(3), $calm(4), $calm(5)];

        self::assertCount(3, WeatherStageRule::HEADWIND->stagesRaising($threeOfFive));
        self::assertSame([], WeatherStageRule::HEADWIND->stagesRaising($twoOfFive));
    }

    #[Test]
    public function derivedThresholdsNeedTheHourlyForecast(): void
    {
        $hot = $this->forecast(apparentTempMax: 35.0);
        $hotWithHourly = $this->forecast(apparentTempMax: 35.0, hourly: [new HourlyWeatherSlot(9, 30.0, 35.0, 5.0, 0, 5.0, 10.0, 0, WeatherForecast::RELATIVE_WIND_CROSSWIND, 50)]);

        self::assertSame([], WeatherStageRule::HEAT->stagesRaising([$this->stage(1, $hot)]));
        self::assertCount(1, WeatherStageRule::HEAT->stagesRaising([$this->stage(1, $hotWithHourly)]));
    }

    private function stage(int $day, ?WeatherForecast $weather): Stage
    {
        $stage = new Stage('trip-1', $day, 50.0, 100.0, new Coordinate(48.0, 2.0), new Coordinate(48.1, 2.1));
        $stage->weather = $weather;

        return $stage;
    }

    /**
     * @param list<HourlyWeatherSlot> $hourly
     */
    private function forecast(
        float $windSpeed = 10.0,
        string $relativeWind = WeatherForecast::RELATIVE_WIND_CROSSWIND,
        float $apparentTempMax = 20.0,
        array $hourly = [],
    ): WeatherForecast {
        return new WeatherForecast(
            icon: 'sunny',
            description: 'Clear',
            tempMin: 10.0,
            tempMax: 20.0,
            windSpeed: $windSpeed,
            windDirection: 'N',
            precipitationProbability: 10,
            humidity: 60,
            comfortIndex: 80,
            relativeWindDirection: $relativeWind,
            apparentTempMin: 10.0,
            apparentTempMax: $apparentTempMax,
            hourly: $hourly,
        );
    }
}
