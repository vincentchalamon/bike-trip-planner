import { describe, it, expect } from "vitest";
import type { StageData } from "@btp/core";
import { computeStageDiff } from "./stage-diff";

/**
 * Direct tests for the stage diff, which only became reachable when it left `use-mercure.ts`
 * (#1330). It was exercised before, but through a hook, a store and a fake SSE event — so a
 * change to its rules could only be observed as a highlight appearing or not, several layers up.
 */

function stage(overrides: Partial<StageData> = {}): StageData {
  return {
    id: "stage-1",
    dayNumber: 1,
    distance: 80,
    elevation: 900,
    elevationLoss: 850,
    startPoint: { lat: 45, lon: 4 },
    endPoint: { lat: 45.5, lon: 4.5 },
    geometry: [],
    startLabel: "Valence",
    endLabel: "Die",
    alerts: [],
    accommodations: [],
    selectedAccommodation: null,
    events: [],
    weather: null,
    resupply: null,
    isRestDay: false,
    ...overrides,
  } as unknown as StageData;
}

function alert(type: string, message: string) {
  return { type, message } as StageData["alerts"][number];
}

describe("computeStageDiff", () => {
  it("reports nothing when the stage did not change", () => {
    expect(computeStageDiff(stage(), stage()).size).toBe(0);
  });

  it("reports a distance change", () => {
    const changed = computeStageDiff(stage(), stage({ distance: 95 }));

    expect([...changed]).toEqual(["distance"]);
  });

  it("reports an added alert", () => {
    const before = stage({ alerts: [alert("weather", "Rain")] });
    const after = stage({
      alerts: [alert("weather", "Rain"), alert("terrain", "Steep climb")],
    });

    expect([...computeStageDiff(before, after)]).toEqual(["alerts_added"]);
  });

  it("reports nothing when an alert DISAPPEARS", () => {
    const before = stage({
      alerts: [alert("weather", "Rain"), alert("terrain", "Steep climb")],
    });
    const after = stage({ alerts: [alert("weather", "Rain")] });

    // Deliberate: an alert going away is good news, and flashing the card for it would draw
    // attention to a problem that no longer exists.
    expect(computeStageDiff(before, after).size).toBe(0);
  });

  it("reports an alert whose message changed under the same type", () => {
    const before = stage({ alerts: [alert("weather", "Rain")] });
    const after = stage({ alerts: [alert("weather", "Heavy rain")] });

    // The identity is type + message — what the user reads — not the stable dismissal `code`.
    expect([...computeStageDiff(before, after)]).toEqual(["alerts_added"]);
  });

  it("reports both when distance and alerts move together", () => {
    const before = stage();
    const after = stage({
      distance: 110,
      alerts: [alert("pacing", "Long day")],
    });

    expect([...computeStageDiff(before, after)].sort()).toEqual([
      "alerts_added",
      "distance",
    ]);
  });
});
