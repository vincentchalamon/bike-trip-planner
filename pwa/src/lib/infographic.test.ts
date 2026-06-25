import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import {
  CARD_WIDTH,
  CARD_HEIGHT,
  renderInfographic,
  stageColor,
  type InfographicData,
} from "./infographic";
import type { StageData } from "./validation/schemas";

const labels: InfographicData["labels"] = {
  distance: "Distance",
  elevation: "Elevation",
  dates: "Dates",
  budget: "Budget",
  difficulty: "Difficulty",
  difficultyEasy: "Easy",
  difficultyMedium: "Medium",
  difficultyHard: "Hard",
  powered: "Bike Trip Planner",
};

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
    startPoint: { lat: 45 + dayNumber * 0.01, lon: 4, ele: 0 },
    endPoint: { lat: 45 + dayNumber * 0.01 + 0.05, lon: 4.1, ele: 0 },
    geometry: [
      { lat: 45 + dayNumber * 0.01, lon: 4, ele: 100 },
      { lat: 45 + dayNumber * 0.01 + 0.025, lon: 4.05, ele: 200 },
      { lat: 45 + dayNumber * 0.01 + 0.05, lon: 4.1, ele: 150 },
    ],
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

/**
 * Build a fake canvas + 2D context that records draw calls without touching
 * the DOM. Mirrors the stub used by the square-infographic suite.
 */
function makeFakeCanvas(): HTMLCanvasElement {
  const ctxStub = {
    canvas: {} as HTMLCanvasElement,
    fillStyle: "",
    strokeStyle: "",
    lineWidth: 0,
    lineCap: "butt",
    lineJoin: "miter",
    font: "",
    textBaseline: "alphabetic",
    textAlign: "left",
    scale: vi.fn(),
    fillRect: vi.fn(),
    fillText: vi.fn(),
    strokeRect: vi.fn(),
    rect: vi.fn(),
    beginPath: vi.fn(),
    closePath: vi.fn(),
    moveTo: vi.fn(),
    lineTo: vi.fn(),
    arc: vi.fn(),
    fill: vi.fn(),
    stroke: vi.fn(),
    save: vi.fn(),
    restore: vi.fn(),
    clip: vi.fn(),
    drawImage: vi.fn(),
    createLinearGradient: vi.fn(() => ({ addColorStop: vi.fn() })),
    measureText: vi.fn((text: string) => ({ width: text.length * 7 })),
  };
  const canvas = {
    width: 0,
    height: 0,
    getContext: vi.fn(() => ctxStub),
  } as unknown as HTMLCanvasElement;
  return canvas;
}

describe("infographic", () => {
  // Stub `Image` so OSM tile loads resolve immediately to `onerror` (treated
  // as a missing tile) instead of hanging on the 5 s timeout.
  let OriginalImage: typeof Image;
  beforeEach(() => {
    OriginalImage = globalThis.Image;
    class FakeImage {
      crossOrigin = "";
      onload: (() => void) | null = null;
      onerror: (() => void) | null = null;
      set src(_value: string) {
        queueMicrotask(() => this.onerror?.());
      }
    }
    // @ts-expect-error - assigning a stub for tests
    globalThis.Image = FakeImage;
  });
  afterEach(() => {
    globalThis.Image = OriginalImage;
  });

  it("exposes 800×480 card dimensions", () => {
    expect(CARD_WIDTH).toBe(800);
    expect(CARD_HEIGHT).toBe(480);
  });

  it("stageColor cycles through the palette and never returns undefined", () => {
    const colors = Array.from({ length: 20 }, (_, i) => stageColor(i));
    for (const c of colors) {
      expect(c).toMatch(/^#[0-9a-f]{6}$/i);
    }
    // Distinct colours for the first three stages (the issue #649 regression).
    expect(new Set([stageColor(0), stageColor(1), stageColor(2)]).size).toBe(3);
    // Cycles back to the first colour past the palette length.
    expect(stageColor(8)).toBe(stageColor(0));
  });

  it("renderInfographic resolves on minimal data", async () => {
    const canvas = makeFakeCanvas();
    await expect(
      renderInfographic(canvas, {
        title: "Test trip",
        totalDistance: 180,
        totalElevation: 2400,
        totalElevationLoss: 2200,
        stages: [
          buildStage(1, 60, 800),
          buildStage(2, 60, 900),
          buildStage(3, 60, 700),
        ],
        startDate: "2026-06-01",
        endDate: "2026-06-03",
        estimatedBudgetMin: 100,
        estimatedBudgetMax: 200,
        labels,
      }),
    ).resolves.toBeUndefined();
    expect(canvas.width).toBeGreaterThanOrEqual(CARD_WIDTH);
    expect(canvas.height).toBeGreaterThanOrEqual(CARD_HEIGHT);
  });

  it("renderInfographic handles empty / rest-day-only trips", async () => {
    const canvas = makeFakeCanvas();
    await expect(
      renderInfographic(canvas, {
        title: "Empty",
        totalDistance: null,
        totalElevation: null,
        totalElevationLoss: null,
        stages: [buildStage(1, 0, 0, true)],
        startDate: null,
        endDate: null,
        estimatedBudgetMin: 0,
        estimatedBudgetMax: 0,
        labels,
      }),
    ).resolves.toBeUndefined();
  });
});
