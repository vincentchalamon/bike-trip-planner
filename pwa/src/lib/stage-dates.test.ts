import { describe, expect, it } from "vitest";
import {
  buildTripText,
  endDateFor,
  stageDate,
  todayUtc,
  type StageData,
} from "@btp/core";
import { renumberAfterStructuralEdit } from "@btp/core/reconciliation";

// Day arithmetic and structural-edit bookkeeping shared by web and mobile
// (core/stage-dates.ts, core/reconciliation.ts, ADR-055).

function stage(dayNumber: number, alerts: StageData["alerts"] = []): StageData {
  return {
    id: `stage-${dayNumber}`,
    dayNumber,
    distance: 50,
    elevation: 100,
    elevationLoss: 100,
    startPoint: { lat: 45, lon: 4, ele: 0 },
    endPoint: { lat: 45, lon: 4.1, ele: 0 },
    geometry: [],
    label: null,
    startLabel: null,
    endLabel: null,
    weather: null,
    alerts,
    resupply: {
      foodAtLunch: [],
      waterMorning: null,
      waterAfternoon: null,
      foodAtArrival: [],
    },
    accommodations: [],
    accommodationSearchRadiusKm: 5,
    isRestDay: false,
    supplyTimeline: [],
    events: [],
  };
}

describe("stageDate / endDateFor", () => {
  it("counts one calendar day per stage", () => {
    expect(stageDate("2026-08-31", 1)).toBe("2026-08-31");
    expect(stageDate("2026-08-31", 2)).toBe("2026-09-01");
    expect(endDateFor("2026-08-01", 3)).toBe("2026-08-03");
  });

  it("reads an API date-time as its calendar day", () => {
    expect(stageDate("2026-08-01T00:00:00+02:00", 2)).toBe("2026-08-02");
  });

  it("returns null without a start date or on garbage", () => {
    expect(stageDate(null, 1)).toBeNull();
    expect(stageDate("nope", 1)).toBeNull();
    expect(endDateFor(null, 3)).toBeNull();
  });

  it("takes today in UTC", () => {
    expect(todayUtc(new Date("2026-08-13T23:30:00Z"))).toBe("2026-08-13");
  });
});

describe("renumberAfterStructuralEdit", () => {
  it("renumbers 1..n and drops only the calendar alerts", () => {
    const alerts: StageData["alerts"] = [
      { group: "calendar", type: "nudge", message: "Sunday" },
      { group: "terrain", type: "warning", message: "Steep" },
    ];
    const stages = renumberAfterStructuralEdit([
      stage(3, alerts),
      stage(1, alerts),
    ]);

    expect(stages.map((s) => s.dayNumber)).toEqual([1, 2]);
    expect(stages.map((s) => s.id)).toEqual(["stage-3", "stage-1"]);
    for (const s of stages) {
      expect(s.alerts.map((a) => a.group)).toEqual(["terrain"]);
    }
  });
});

describe("buildTripText dates", () => {
  const labels = { totalDistance: "Distance", totalElevation: "Dénivelé" };

  // It used to format in the device locale and count from the device clock.
  it("writes the stage dates in the injected locale", () => {
    const text = buildTripText({
      title: "T",
      totalDistance: 50,
      totalElevation: 100,
      totalElevationLoss: 100,
      sourceUrl: "",
      stages: [stage(1), stage(2)],
      startDate: "2026-06-01",
      locale: "fr",
      today: "2026-01-01",
      labels,
    });

    expect(text).toContain("lun. 1 juin 2026");
    expect(text).toContain("mar. 2 juin 2026");
  });

  it("counts from the injected today without a start date", () => {
    const text = buildTripText({
      title: "T",
      totalDistance: 50,
      totalElevation: 100,
      totalElevationLoss: 100,
      sourceUrl: "",
      stages: [stage(1)],
      startDate: null,
      locale: "en-GB",
      today: "2026-03-15",
      labels,
    });

    expect(text).toContain("Sun, 15 March 2026");
  });
});
