import { describe, expect, it } from "vitest";
import { formatDayDate, formatDayHeading } from "./StageDetailPanel";

describe("formatDayDate (recette #649 #6)", () => {
  it("formats the day date in English", () => {
    expect(formatDayDate("2026-07-12", 1, "en")).toBe("Sunday 12 July 2026");
  });

  it("formats the day date in French", () => {
    expect(formatDayDate("2026-07-12", 1, "fr")).toBe(
      "dimanche 12 juillet 2026",
    );
  });

  it("offsets the date by the day number", () => {
    expect(formatDayDate("2026-07-12", 3, "en")).toBe("Tuesday 14 July 2026");
  });
});

describe("formatDayHeading (recette — no fabricated date without dates)", () => {
  it("returns the capitalised weekday date when a start date is set", () => {
    expect(formatDayHeading("2026-07-12", 1, "fr")).toBe(
      "Dimanche 12 juillet 2026",
    );
  });

  it("returns null with no start date so the caller falls back to 'Jour N'", () => {
    expect(formatDayHeading(null, 2, "fr")).toBeNull();
  });
});
