import { describe, it, expect, vi, beforeEach } from "vitest";
import { EMPTY_RESUPPLY, type StageData } from "@btp/core";

const pending = vi.hoisted(
  () => [] as ((result: { name: string } | null) => void)[],
);

vi.mock("@/lib/geocode/client", () => ({
  reverseGeocode: () =>
    new Promise<{ name: string } | null>((resolve) => pending.push(resolve)),
}));

import { resolveStageLabels } from "./stage-labels";
import { useTripStore } from "@/store/trip-store";

function stage(id: string, dayNumber: number): StageData {
  const point = { lat: dayNumber, lon: dayNumber, ele: 0 };

  return {
    id,
    dayNumber,
    distance: 50,
    elevation: 0,
    elevationLoss: 0,
    startPoint: point,
    endPoint: point,
    geometry: [],
    label: null,
    startLabel: null,
    endLabel: null,
    weather: null,
    alerts: [],
    resupply: EMPTY_RESUPPLY,
    accommodations: [],
    selectedAccommodation: null,
    accommodationSearchRadiusKm: 10,
    isRestDay: false,
    supplyTimeline: [],
    events: [],
  };
}

beforeEach(() => {
  pending.length = 0;
  useTripStore.getState().clearTrip();
});

describe("resolveStageLabels", () => {
  it("labels the stage it geocoded even when the stages moved while Nominatim answered", async () => {
    const second = stage("s2", 2);
    useTripStore.setState({ stages: [stage("s1", 1), second] });

    const done = resolveStageLabels([second]);
    // A day inserted in front shifts every index while the requests are in flight.
    useTripStore.setState({
      stages: [stage("s0", 1), stage("s1", 2), { ...second, dayNumber: 3 }],
    });
    pending.forEach((resolve) => resolve({ name: "Lyon" }));
    await done;

    const stages = useTripStore.getState().stages;
    expect(stages.find((s) => s.id === "s2")?.startLabel).toBe("Lyon");
    expect(stages.find((s) => s.id === "s1")?.startLabel).toBeNull();
  });

  it("drops the label of a stage that no longer exists", async () => {
    const gone = stage("gone", 1);
    useTripStore.setState({ stages: [gone] });

    const done = resolveStageLabels([gone]);
    useTripStore.setState({ stages: [stage("other", 1)] });
    pending.forEach((resolve) => resolve({ name: "Lyon" }));
    await done;

    expect(useTripStore.getState().stages[0]?.startLabel).toBeNull();
  });
});
