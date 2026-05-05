import { render } from "@testing-library/react";
import { describe, it, expect, vi, afterEach } from "vitest";
import "@testing-library/jest-dom/vitest";
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

  it("shows the Sun pill during daylight (data-state=\"day\")", () => {
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

  it("shows the Moon pill at night (data-state=\"night\")", () => {
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
