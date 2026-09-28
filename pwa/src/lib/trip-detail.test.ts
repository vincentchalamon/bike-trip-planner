import { describe, expect, it } from "vitest";
import {
  DEFAULT_TRIP_SETTINGS,
  EMPTY_RESUPPLY,
  stageDataFromDetail,
  tripSettingsFromDetail,
} from "@btp/core";
import { DEFAULT_ACCOMMODATION_RADIUS_KM } from "@btp/core/constants";

// The /detail -> store mapping shared by the web trip page, the web shared page
// and both mobile hydrates (core/trip-detail.ts, ADR-055).

describe("stageDataFromDetail", () => {
  it("keeps every persisted field the copies used to drop", () => {
    const marker = {
      type: "water" as const,
      distanceFromStart: 12,
      lat: 1,
      lon: 1,
      water: [],
      food: [],
    };
    const event = {
      name: "Fête",
      type: "festival",
      lat: 1,
      lon: 1,
      startDate: "2026-08-01T00:00:00+00:00",
      endDate: "2026-08-02T00:00:00+00:00",
      distanceToEndPoint: 0,
      source: "datatourisme",
    };
    const stage = stageDataFromDetail({
      stageId: "s1",
      dayNumber: 2,
      onCycleNetwork: 0.64,
      supplyTimeline: [marker],
      events: [event],
    });

    // The shared page hardcoded both lists to [] and mobile dropped the share.
    expect(stage.supplyTimeline).toEqual([marker]);
    expect(stage.events).toEqual([event]);
    expect(stage.onCycleNetwork).toBe(0.64);
    expect(stage.id).toBe("s1");
    expect(stage.dayNumber).toBe(2);
  });

  it("defaults what the summary does not carry", () => {
    const stage = stageDataFromDetail({});

    expect(stage.geometry).toEqual([]);
    expect(stage.startPoint).toEqual({ lat: 0, lon: 0, ele: 0 });
    expect(stage.endPoint).toEqual({ lat: 0, lon: 0, ele: 0 });
    expect(stage.resupply).toEqual(EMPTY_RESUPPLY);
    expect(stage.alerts).toEqual([]);
    expect(stage.weather).toBeNull();
    expect(stage.selectedAccommodation).toBeNull();
    expect(stage.onCycleNetwork).toBe(0);
    expect(stage.accommodationSearchRadiusKm).toBe(
      DEFAULT_ACCOMMODATION_RADIUS_KM,
    );
  });

  it("fills a partial coordinate instead of passing it through", () => {
    expect(
      stageDataFromDetail({ endPoint: { lat: 45, lon: 5 } }).endPoint,
    ).toEqual({ lat: 45, lon: 5, ele: 0 });
  });
});

describe("tripSettingsFromDetail", () => {
  it("reads the dates as calendar days and keeps the preferences", () => {
    expect(
      tripSettingsFromDetail({
        startDate: "2026-08-01T00:00:00+02:00",
        endDate: "2026-08-03T00:00:00+02:00",
        fatigueFactor: 0.7,
        elevationPenalty: 40,
        maxDistancePerDay: 100,
        averageSpeed: 18,
        ebikeMode: true,
        departureHour: 6,
        enabledAccommodationTypes: ["hotel"],
      }),
    ).toEqual({
      startDate: "2026-08-01",
      endDate: "2026-08-03",
      fatigueFactor: 0.7,
      elevationPenalty: 40,
      maxDistancePerDay: 100,
      averageSpeed: 18,
      ebikeMode: true,
      departureHour: 6,
      enabledAccommodationTypes: ["hotel"],
    });
  });

  it("falls back to the defaults of a new trip", () => {
    expect(tripSettingsFromDetail({})).toEqual(DEFAULT_TRIP_SETTINGS);
  });
});
