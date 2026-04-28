import { describe, it, expect } from "vitest";
import {
  formatOpeningHoursStatus,
  parseOpeningHours,
} from "./poi-opening-hours";

describe("parseOpeningHours", () => {
  it("returns a single rule for 24/7", () => {
    const rules = parseOpeningHours("24/7");
    expect(rules).toHaveLength(1);
    const rule = rules[0]!;
    expect(rule.days.size).toBe(7);
    expect(rule.intervals).toEqual([{ start: 0, end: 1440 }]);
  });

  it("parses single day range", () => {
    const rules = parseOpeningHours("Mo-Fr 09:00-18:00");
    expect(rules).toHaveLength(1);
    const rule = rules[0]!;
    expect(Array.from(rule.days).sort()).toEqual([0, 1, 2, 3, 4]);
    expect(rule.intervals).toEqual([{ start: 540, end: 1080 }]);
  });

  it("parses split-day intervals", () => {
    const rules = parseOpeningHours("Mo,We,Fr 09:00-12:00,14:00-18:00");
    expect(rules).toHaveLength(1);
    const rule = rules[0]!;
    expect(Array.from(rule.days).sort()).toEqual([0, 2, 4]);
    expect(rule.intervals).toEqual([
      { start: 540, end: 720 },
      { start: 840, end: 1080 },
    ]);
  });

  it("parses multi-rule strings", () => {
    const rules = parseOpeningHours("Mo-Fr 09:00-18:00; Sa,Su 10:00-17:00");
    expect(rules).toHaveLength(2);
    expect(Array.from(rules[1]!.days).sort()).toEqual([5, 6]);
  });

  it("returns empty list for unparsable strings", () => {
    expect(parseOpeningHours("dimanche après-midi")).toEqual([]);
  });
});

describe("formatOpeningHoursStatus — French", () => {
  // Wednesday 2026-04-29 14:00 — picked so it sits inside Mo-Fr 09-18 windows.
  const wednesdayAfternoon = new Date(2026, 3, 29, 14, 0);

  it("reports open until closing time during the day", () => {
    const status = formatOpeningHoursStatus(
      "Mo-Fr 09:00-18:00",
      "fr",
      wednesdayAfternoon,
    );
    expect(status.isOpen).toBe(true);
    expect(status.label).toBe("Ouvert jusqu'à 18h");
  });

  it("reports closed/opens-tomorrow after closing", () => {
    const status = formatOpeningHoursStatus(
      "Mo-Fr 09:00-18:00",
      "fr",
      new Date(2026, 3, 29, 19, 30), // Wednesday 19:30
    );
    expect(status.isOpen).toBe(false);
    expect(status.label).toBe("Fermé, ouvre demain à 9h");
  });

  it("reports closed/opens-today before opening", () => {
    const status = formatOpeningHoursStatus(
      "Mo-Fr 09:00-18:00",
      "fr",
      new Date(2026, 3, 29, 7, 0), // Wednesday 07:00
    );
    expect(status.isOpen).toBe(false);
    expect(status.label).toBe("Fermé, ouvre à 9h");
  });

  it("reports 24/7 as always open", () => {
    const status = formatOpeningHoursStatus("24/7", "fr", wednesdayAfternoon);
    expect(status.isOpen).toBe(true);
    expect(status.label).toBe("Ouvert 24h/24");
  });

  it("falls back to the raw string when unparsable", () => {
    const status = formatOpeningHoursStatus(
      "uniquement sur réservation",
      "fr",
      wednesdayAfternoon,
    );
    expect(status.isOpen).toBe(false);
    expect(status.label).toBe("uniquement sur réservation");
  });
});

describe("formatOpeningHoursStatus — English", () => {
  const wednesdayAfternoon = new Date(2026, 3, 29, 14, 0);

  it("reports open with English wording", () => {
    const status = formatOpeningHoursStatus(
      "Mo-Fr 09:00-18:00",
      "en",
      wednesdayAfternoon,
    );
    expect(status.isOpen).toBe(true);
    expect(status.label).toBe("Open until 6 PM");
  });

  it("reports closed with English wording", () => {
    const status = formatOpeningHoursStatus(
      "Mo-Fr 09:00-18:00",
      "en",
      new Date(2026, 3, 29, 19, 30),
    );
    expect(status.isOpen).toBe(false);
    expect(status.label).toBe("Closed, opens tomorrow at 9 AM");
  });
});
