import { render } from "@testing-library/react";
import { describe, it, expect, vi, afterEach } from "vitest";
import "@testing-library/jest-dom/vitest";
import type { WeatherData } from "@btp/core";
import { isSunUp, StageWeatherCard } from "./StageWeatherCard";

// Stub next-intl to avoid wiring a NextIntlClientProvider in unit tests.
// Returning the key suffix is enough to assert rendering logic.
vi.mock("next-intl", () => ({
  useTranslations: () => (key: string) => key,
}));

describe("isSunUp", () => {
  it("returns null when the stage is not today", () => {
    const stageDate = new Date(Date.UTC(2030, 0, 15));
    const now = new Date(Date.UTC(2030, 0, 14, 12, 0, 0));
    // Sunrise 6h, sunset 20h — irrelevant since stage is tomorrow.
    expect(isSunUp(stageDate, 6, 20, now)).toBeNull();
  });

  it("returns null when sunrise/sunset are unavailable (polar)", () => {
    const today = new Date(Date.UTC(2030, 5, 21));
    const now = new Date(Date.UTC(2030, 5, 21, 12, 0, 0));
    expect(isSunUp(today, null, null, now)).toBeNull();
  });

  it("returns true when current UTC time is between sunrise and sunset", () => {
    const today = new Date(Date.UTC(2030, 5, 21));
    const now = new Date(Date.UTC(2030, 5, 21, 12, 0, 0));
    expect(isSunUp(today, 5.5, 20.5, now)).toBe(true);
  });

  it("returns false before sunrise on the stage day", () => {
    const today = new Date(Date.UTC(2030, 5, 21));
    const now = new Date(Date.UTC(2030, 5, 21, 4, 0, 0));
    expect(isSunUp(today, 5.5, 20.5, now)).toBe(false);
  });

  it("returns false after sunset on the stage day", () => {
    const today = new Date(Date.UTC(2030, 5, 21));
    const now = new Date(Date.UTC(2030, 5, 21, 22, 0, 0));
    expect(isSunUp(today, 5.5, 20.5, now)).toBe(false);
  });
});

describe("StageWeatherCard forecast-horizon message", () => {
  afterEach(() => {
    vi.useRealTimers();
  });

  const weather: WeatherData = {
    icon: "01d",
    description: "Clear",
    tempMin: 10,
    tempMax: 20,
    windSpeed: 5,
    windDirection: "N",
    precipitationProbability: 0,
    humidity: 50,
    comfortIndex: 90,
    relativeWindDirection: "unknown",
    apparentTempMin: 9,
    apparentTempMax: 19,
    windGusts: 8,
    precipitationMm: 0,
    uvIndex: 1,
    hourly: [],
  };

  it("shows the horizon notice for a stage beyond the 16-day window with no weather", () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(Date.UTC(2030, 0, 1, 12, 0, 0)));

    const { getByTestId } = render(
      <StageWeatherCard weather={null} startDate="2030-06-01" stageIndex={0} />,
    );

    expect(getByTestId("stage-weather-horizon")).toBeInTheDocument();
  });

  it("hides the horizon notice for a stage within the 16-day window", () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(Date.UTC(2030, 0, 1, 12, 0, 0)));

    const { queryByTestId } = render(
      <StageWeatherCard weather={null} startDate="2030-01-03" stageIndex={0} />,
    );

    expect(queryByTestId("stage-weather-horizon")).toBeNull();
  });

  it("hides the horizon notice when weather is present, even far in the future", () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(Date.UTC(2030, 0, 1, 12, 0, 0)));

    const { queryByTestId } = render(
      <StageWeatherCard
        weather={weather}
        startDate="2030-06-01"
        stageIndex={0}
      />,
    );

    expect(queryByTestId("stage-weather-horizon")).toBeNull();
  });
});

describe("StageWeatherCard sun-state pill", () => {
  afterEach(() => {
    vi.useRealTimers();
  });

  // Equatorial reference point keeps sunrise/sunset close to 06:00/18:00 UTC,
  // which gives stable "is the sun up" expectations independent of the season.
  const endPointLat = 0;
  const endPointLon = 0;

  it("hides the pill when isSunUp returns null (stage not today)", () => {
    // Pin "now" to a date that is not the trip start date.
    vi.useFakeTimers();
    vi.setSystemTime(new Date(Date.UTC(2030, 5, 22, 12, 0, 0)));

    const { queryByTestId } = render(
      <StageWeatherCard
        weather={null}
        startDate="2030-06-21"
        stageIndex={0}
        endPointLat={endPointLat}
        endPointLon={endPointLon}
      />,
    );

    // Sun-times footer is rendered (sunrise/sunset known) but the live pill is not.
    expect(queryByTestId("stage-weather-sun-times")).not.toBeNull();
    expect(queryByTestId("stage-weather-sun-state")).toBeNull();
  });

  it('shows the Sun pill during daylight (data-state="day")', () => {
    // Stage is today, noon UTC at the equator → sun is up.
    vi.useFakeTimers();
    vi.setSystemTime(new Date(Date.UTC(2030, 5, 21, 12, 0, 0)));

    const { getByTestId } = render(
      <StageWeatherCard
        weather={null}
        startDate="2030-06-21"
        stageIndex={0}
        endPointLat={endPointLat}
        endPointLon={endPointLon}
      />,
    );

    const pill = getByTestId("stage-weather-sun-state");
    expect(pill).toHaveAttribute("data-state", "day");
  });

  it('shows the Moon pill at night (data-state="night")', () => {
    // Stage is today, 23:00 UTC at the equator → sun is down.
    vi.useFakeTimers();
    vi.setSystemTime(new Date(Date.UTC(2030, 5, 21, 23, 0, 0)));

    const { getByTestId } = render(
      <StageWeatherCard
        weather={null}
        startDate="2030-06-21"
        stageIndex={0}
        endPointLat={endPointLat}
        endPointLon={endPointLon}
      />,
    );

    const pill = getByTestId("stage-weather-sun-state");
    expect(pill).toHaveAttribute("data-state", "night");
  });
});
