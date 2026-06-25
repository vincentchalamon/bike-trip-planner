import { describe, it, expect } from "vitest";
import { buildTripText } from "./text-export";
import type { StageData } from "./validation/schemas";

function buildStage(
  dayNumber: number,
  distance: number,
  elevation: number,
  isRestDay = false,
): StageData {
  return {
    dayNumber,
    distance,
    elevation,
    elevationLoss: elevation,
    startPoint: { lat: 45, lon: 4, ele: 0 },
    endPoint: { lat: 45, lon: 4.1, ele: 0 },
    geometry: [],
    label: `Stage ${dayNumber}`,
    startLabel: null,
    endLabel: null,
    weather: null,
    alerts: [],
    pois: [],
    accommodations: [],
    accommodationSearchRadiusKm: 5,
    isRestDay,
    supplyTimeline: [],
    events: [],
  };
}

const labels = { totalDistance: "Distance totale", totalElevation: "Dénivelé" };

describe("buildTripText", () => {
  it("contains no literal bold markers", () => {
    const text = buildTripText({
      title: "Mon voyage",
      totalDistance: 120,
      totalElevation: 1500,
      totalElevationLoss: 1400,
      sourceUrl: "https://www.komoot.com/tour/123",
      stages: [buildStage(1, 60, 800), buildStage(2, 60, 700)],
      startDate: "2026-06-25",
      labels,
    });

    expect(text).not.toContain("*");
  });

  it("renders the title and date lines without asterisks", () => {
    const text = buildTripText({
      title: "Mon voyage",
      totalDistance: 60,
      totalElevation: 800,
      totalElevationLoss: 800,
      sourceUrl: "",
      stages: [buildStage(1, 60, 800)],
      startDate: "2026-06-25",
      labels,
    });

    const lines = text.split("\n");
    expect(lines[0]).toBe("Mon voyage");
    expect(lines.some((l) => l.includes("*"))).toBe(false);
  });

  it("uses the bike emoji without a dangling zero-width joiner", () => {
    const text = buildTripText({
      title: "T",
      totalDistance: 10,
      totalElevation: null,
      totalElevationLoss: null,
      sourceUrl: "",
      stages: [],
      startDate: null,
      labels,
    });

    expect(text).toContain("🚴 Distance totale");
    expect(text).not.toContain("‍");
  });
});
