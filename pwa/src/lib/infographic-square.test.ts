import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import {
  MAX_STAGE_LIST,
  SQUARE_INFOGRAPHIC_SIZE,
  renderSquareInfographic,
  type SquareInfographicData,
} from "./infographic-square";
import type { StageData } from "./validation/schemas";

const labels: SquareInfographicData["labels"] = {
  distance: "Distance",
  elevation: "Elevation",
  days: "Days",
  budget: "Budget",
  stagesHeading: "Stages",
  restDay: "—",
  more: "+ 0 more stages",
  poweredBy: "Bike Trip Planner",
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
 * Build a fake canvas + 2D context that records draw calls without
 * touching the DOM. Sufficient for verifying the renderer doesn't throw
 * and exercises the public API end-to-end.
 */
function makeFakeCanvas(
  measureText?: (text: string) => { width: number },
): HTMLCanvasElement {
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
    beginPath: vi.fn(),
    closePath: vi.fn(),
    moveTo: vi.fn(),
    lineTo: vi.fn(),
    quadraticCurveTo: vi.fn(),
    arc: vi.fn(),
    fill: vi.fn(),
    stroke: vi.fn(),
    save: vi.fn(),
    restore: vi.fn(),
    clip: vi.fn(),
    drawImage: vi.fn(),
    createLinearGradient: vi.fn(() => ({ addColorStop: vi.fn() })),
    measureText: vi.fn(
      measureText ?? ((text: string) => ({ width: text.length * 7 })),
    ),
  };
  const canvas = {
    width: 0,
    height: 0,
    getContext: vi.fn(() => ctxStub),
  } as unknown as HTMLCanvasElement;
  return canvas;
}

describe("infographic-square", () => {
  // Stub `Image` so OSM tile loads resolve immediately to `onerror` (which
  // the renderer treats as a missing tile) instead of hanging on the 5 s
  // timeout while jsdom silently drops the request.
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

  it("exposes a 1080×1080 export size", () => {
    expect(SQUARE_INFOGRAPHIC_SIZE).toBe(1080);
  });

  it("caps stage list at MAX_STAGE_LIST entries", () => {
    expect(MAX_STAGE_LIST).toBe(6);
  });

  it("renderSquareInfographic resolves on minimal data", async () => {
    const canvas = makeFakeCanvas();
    await expect(
      renderSquareInfographic(canvas, {
        title: "Test trip",
        totalDistance: 120,
        totalElevation: 800,
        stages: [buildStage(1, 60, 400), buildStage(2, 60, 400)],
        estimatedBudgetMin: 100,
        estimatedBudgetMax: 200,
        labels,
      }),
    ).resolves.toBeUndefined();
    expect(canvas.width).toBeGreaterThanOrEqual(1080);
    expect(canvas.height).toBeGreaterThanOrEqual(1080);
  });

  it("renderSquareInfographic handles empty / rest-day-only trips", async () => {
    const canvas = makeFakeCanvas();
    await expect(
      renderSquareInfographic(canvas, {
        title: "Empty",
        totalDistance: null,
        totalElevation: null,
        stages: [buildStage(1, 0, 0, true)],
        estimatedBudgetMin: 0,
        estimatedBudgetMax: 0,
        labels,
      }),
    ).resolves.toBeUndefined();
  });

  it("preserves the last wrapped title line when the title exceeds maxLines at a word boundary", async () => {
    // Stub measureText so each word is ~500px wide. At the title maxWidth
    // of 968 (1080 − 2 × 56 padding), one word fits per line but two
    // words (1000px) do not. With a 3-word title and maxLines=2, the
    // wrap loop pushes "W1", then pushes "W2" to hit the maxLines budget
    // with `current = ""` and `i = 2`. Without the `&& current` guard,
    // the truncate branch overwrites lines[1] with words.slice(2) → "W3",
    // silently dropping "W2".
    const measureText = (t: string) => ({
      width: t.split(" ").filter(Boolean).length * 500,
    });
    const canvas = makeFakeCanvas(measureText);
    await renderSquareInfographic(canvas, {
      title: "W1 W2 W3",
      totalDistance: 120,
      totalElevation: 800,
      stages: [buildStage(1, 60, 400), buildStage(2, 60, 400)],
      estimatedBudgetMin: 100,
      estimatedBudgetMax: 200,
      labels,
    });
    const ctx = (
      canvas.getContext as unknown as {
        mock: { results: Array<{ value: { fillText: { mock: { calls: unknown[][] } } } }> };
      }
    ).mock.results[0]!.value;
    const fillTextCalls = ctx.fillText.mock.calls.map(
      (call) => call[0] as string,
    );
    // The second wrapped title line must survive: "W2" should be drawn.
    expect(fillTextCalls).toContain("W2");
    // And the regression: line 2 must not have been overwritten with "W3".
    expect(fillTextCalls).not.toContain("W3");
  });

  it("renderSquareInfographic truncates long stage lists", async () => {
    const canvas = makeFakeCanvas();
    const stages = Array.from({ length: 10 }, (_, i) =>
      buildStage(i + 1, 50 + i, 400 + i * 10),
    );
    await expect(
      renderSquareInfographic(canvas, {
        title: "Long trip",
        totalDistance: 600,
        totalElevation: 5000,
        stages,
        estimatedBudgetMin: 0,
        estimatedBudgetMax: 0,
        labels: { ...labels, more: "+ 4 more stages" },
      }),
    ).resolves.toBeUndefined();
  });
});
