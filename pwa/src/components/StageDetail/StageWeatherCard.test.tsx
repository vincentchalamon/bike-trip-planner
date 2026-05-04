import { describe, it, expect } from "vitest";
import { isSunUp } from "./StageWeatherCard";

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
