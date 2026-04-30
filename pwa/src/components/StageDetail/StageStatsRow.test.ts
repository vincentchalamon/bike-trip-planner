import { describe, expect, it } from "vitest";
import { formatDuration } from "./StageStatsRow";

describe("formatDuration", () => {
  it("returns 0h00 for 0 hours", () => {
    expect(formatDuration(0)).toBe("0h00");
  });

  it("returns 1h00 for exactly 1 hour", () => {
    expect(formatDuration(1)).toBe("1h00");
  });

  it("returns 1h30 for 1.5 hours", () => {
    expect(formatDuration(1.5)).toBe("1h30");
  });

  it("carries over into the next hour when minutes round to 60", () => {
    // 1 + 59.5/60 ≈ 1.9916… → h=1, m=round(0.9916…*60)=round(59.5)=60 → carry
    expect(formatDuration(1 + 59.5 / 60)).toBe("2h00");
  });

  it("returns 2h15 for 2.25 hours", () => {
    expect(formatDuration(2.25)).toBe("2h15");
  });

  it("clamps negative values to 0h00", () => {
    expect(formatDuration(-1)).toBe("0h00");
  });
});
