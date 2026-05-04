import { describe, expect, it } from "vitest";
import {
  scoreElevation,
  scorePhysical,
  scoreTechnical,
  scoreToDifficulty,
} from "./StageDifficultyComposed";

describe("scorePhysical", () => {
  it("returns 0 for 0 km", () => {
    expect(scorePhysical(0)).toBe(0);
  });

  it("clamps negative distances to 0", () => {
    expect(scorePhysical(-10)).toBe(0);
  });

  it("returns a value between 0 and 100 for a mid-range distance", () => {
    const score = scorePhysical(70);
    expect(score).toBeGreaterThan(0);
    expect(score).toBeLessThan(100);
  });

  it("returns 50 around half of the cap (70 km out of 140)", () => {
    expect(scorePhysical(70)).toBe(50);
  });

  it("saturates at 100 when distance reaches the cap (140 km)", () => {
    expect(scorePhysical(140)).toBe(100);
  });

  it("saturates at 100 for distances above the cap", () => {
    expect(scorePhysical(500)).toBe(100);
  });
});

describe("scoreElevation", () => {
  it("returns 0 for 0 m", () => {
    expect(scoreElevation(0)).toBe(0);
  });

  it("clamps negative elevations to 0", () => {
    expect(scoreElevation(-100)).toBe(0);
  });

  it("returns a value between 0 and 100 for a mid-range elevation", () => {
    const score = scoreElevation(1250);
    expect(score).toBeGreaterThan(0);
    expect(score).toBeLessThan(100);
  });

  it("returns 50 around half of the cap (1250 m out of 2500)", () => {
    expect(scoreElevation(1250)).toBe(50);
  });

  it("saturates at 100 when elevation reaches the cap (2500 m)", () => {
    expect(scoreElevation(2500)).toBe(100);
  });

  it("saturates at 100 for elevations above the cap", () => {
    expect(scoreElevation(5000)).toBe(100);
  });
});

describe("scoreTechnical", () => {
  it("returns 0 when distance is 0 (avoids divide-by-zero)", () => {
    expect(scoreTechnical(0, 1000)).toBe(0);
  });

  it("returns 0 for negative distances", () => {
    expect(scoreTechnical(-10, 500)).toBe(0);
  });

  it("returns 0 for a flat ride (no elevation gain)", () => {
    expect(scoreTechnical(100, 0)).toBe(0);
  });

  it("returns 50 for a 30 m/km ratio (half of the 60 m/km cap)", () => {
    expect(scoreTechnical(100, 3000)).toBe(50);
  });

  it("saturates at 100 when ratio reaches the cap (60 m/km)", () => {
    expect(scoreTechnical(50, 3000)).toBe(100);
  });

  it("saturates at 100 for ratios above the cap", () => {
    expect(scoreTechnical(10, 1000)).toBe(100);
  });
});

describe("scoreToDifficulty", () => {
  it("maps low scores to easy", () => {
    expect(scoreToDifficulty(0)).toBe("easy");
    expect(scoreToDifficulty(33)).toBe("easy");
  });

  it("maps mid scores to medium", () => {
    expect(scoreToDifficulty(34)).toBe("medium");
    expect(scoreToDifficulty(66)).toBe("medium");
  });

  it("maps high scores to hard", () => {
    expect(scoreToDifficulty(67)).toBe("hard");
    expect(scoreToDifficulty(100)).toBe("hard");
  });
});
