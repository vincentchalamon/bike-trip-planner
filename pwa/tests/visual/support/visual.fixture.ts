import {
  test as base,
  expect,
  type Page,
  type Locator,
} from "@playwright/test";
import {
  mockAllApis,
  getTripId,
  type MockApiOptions,
} from "../../fixtures/api-mocks";
import { injectSseSequence } from "../../fixtures/sse-helpers";
import { fullTripEventSequence } from "../../fixtures/mock-data";
import { expandLinkCard } from "../../fixtures/base.fixture";

export { expect };

/**
 * Visual-regression fixture for the **authenticated** screens.
 *
 * The public-pages baselines (`pages.spec.ts`) only need theme + locale; this
 * fixture additionally wires the full mock chain (`mockAllApis` + SSE event
 * injection) so the planner, roadbook, processing and trips-list screens can be
 * rendered deterministically without a live backend.
 *
 * Theme/locale come from the project metadata declared in
 * `playwright.visual.config.ts` (the 6 device x theme x lang combos), applied
 * the same way as in `pages.spec.ts`:
 *  - the `locale` cookie is anchored to the project baseURL,
 *  - next-themes' persisted `theme` key is seeded before first paint.
 */

interface VisualMetadata {
  theme: string;
  appLocale: string;
}

/**
 * Selectors for regions whose pixels are non-deterministic across runs and must
 * be masked in `toHaveScreenshot`:
 *  - MapLibre canvas + tiles (network-loaded, animated),
 *  - any `<canvas>` (infographic preview, elevation profile rendering),
 *  - dates/times (locale-formatted, "today"-relative),
 *  - AI narrative blocks (streamed / model-dependent text).
 */
export const MASK_SELECTORS = [
  ".maplibregl-map",
  "[data-testid='map']",
  "[data-testid='map-container']",
  "[data-testid='map-panel']",
  "[data-testid='trip-preview-map']",
  "canvas",
  "[data-testid='summary-dates']",
  "[data-testid='stage-weather-card']",
  "[data-testid='stage-ai-summary']",
  "[data-testid='trip-ai-overview']",
];

export function maskRegions(page: Page): Locator[] {
  return [page.locator(MASK_SELECTORS.join(", "))];
}

/**
 * The map-bearing planner screens (roadbook, wizard preview/processing, and the
 * modals opened on top of them) are not deterministic VR targets on two combos:
 *  - **Firefox** (container) has no WebGL, so MapLibre throws on init and the
 *    screen hits the `TripPlannerErrorBoundary` instead of rendering.
 *  - **Mobile (<700px)** stacks the roadbook vertically, where the MapLibre
 *    canvas + tile-driven content settle to a slightly different full-page
 *    height between runs (e.g. 2302 vs 2318px) — a dimension mismatch that
 *    fails the screenshot regardless of `maxDiffPixelRatio`.
 * These baselines are captured on the desktop/tablet chromium+webkit combos;
 * firefox and the 375px mobiles skip them. Pure-DOM screens (public pages, trips
 * list, account, 404, trip-error) are captured on every combo. Mobile roadbook
 * polish stays a manual recette check; functional coverage is in the
 * mocked/recette suites.
 */
export const MAP_SCREEN_SKIP_REASON =
  "Map-bearing planner screen: non-deterministic VR on Firefox (no WebGL) and mobile (<700px, variable full-page height). Covered on desktop/tablet combos + functionally by the mocked/recette suites.";

/** True when the current project can't produce a stable map-screen baseline. */
export function shouldSkipMapScreen(testInfo: {
  project: { name: string; use: { viewport?: { width: number } | null } };
}): boolean {
  const width = testInfo.project.use.viewport?.width ?? 9999;
  return testInfo.project.name.includes("firefox") || width < 700;
}

interface VisualFixtures {
  /**
   * A page with the full API mock chain installed, theme/locale applied, and
   * (when relevant) the planner welcome screen ready. Navigation is left to the
   * test so it can target any route.
   */
  visualPage: Page;
  /** Drive the planner to the wizard step-2 "preview" view (stage cards). */
  gotoPreview: () => Promise<void>;
  /** Drive the planner to the wizard step-3 "processing/analysis" view. */
  gotoProcessing: () => Promise<void>;
  /**
   * Navigate to `/trips/[id]` with a stages-bearing detail response and wait
   * for the roadbook master-detail view. The most deterministic loaded-trip
   * path (no SSE timing); the TopBar then exposes share/config/help actions.
   */
  gotoRoadbook: () => Promise<void>;
}

export const test = base.extend<
  VisualFixtures & { mockOptions: MockApiOptions }
>({
  mockOptions: [{}, { option: true }],

  visualPage: async ({ page, baseURL, mockOptions }, use, testInfo) => {
    const { theme, appLocale } = testInfo.project.metadata as VisualMetadata;
    const url = baseURL ?? "https://localhost";
    await page
      .context()
      .addCookies([{ name: "locale", value: appLocale, url }]);
    await page.addInitScript((value) => {
      try {
        window.localStorage.setItem("theme", value);
      } catch {
        /* storage unavailable — colorScheme still drives prefers-color-scheme */
      }
    }, theme);
    await mockAllApis(page, mockOptions);
    await use(page);
  },

  gotoPreview: async ({ visualPage }, use) => {
    await use(async () => {
      await submitUrlAndAwaitTrip(visualPage);
      await injectSseSequence(visualPage, fullTripEventSequence());
      await expect(visualPage.getByTestId("stage-card-3")).toBeVisible({
        timeout: 10000,
      });
    });
  },

  gotoProcessing: async ({ visualPage }, use) => {
    await use(async () => {
      await submitUrlAndAwaitTrip(visualPage);
      // Compute stages, then simulate the "user clicked Launch analysis" gate so
      // the narrative progress screen stays mounted (see processing-progress.spec).
      await injectSseSequence(visualPage, fullTripEventSequence().slice(0, 2));
      await visualPage.evaluate(() => {
        window.dispatchEvent(
          new CustomEvent("__test_set_processing", { detail: true }),
        );
        window.dispatchEvent(
          new CustomEvent("__test_set_analysis_started", { detail: true }),
        );
      });
      await expect(visualPage.getByTestId("processing-progress")).toBeVisible({
        timeout: 10000,
      });
    });
  },

  gotoRoadbook: async ({ visualPage }, use) => {
    await use(async () => {
      await visualPage.route("**/trips/*/detail", (route, request) => {
        if (request.method() !== "GET") return route.fallback();
        return route.fulfill({
          status: 200,
          contentType: "application/ld+json",
          body: JSON.stringify(roadbookDetail()),
        });
      });
      await visualPage.goto(`/trips/${getTripId()}`);
      await expect(
        visualPage.getByTestId("roadbook-master-detail"),
      ).toBeVisible({ timeout: 15000 });
      // Hold a beat so the map + any entrance transition settle.
      await visualPage.waitForTimeout(500);
    });
  },
});

/** Stages-bearing trip detail used by the roadbook baseline + modal states. */
export function roadbookDetail() {
  return {
    "@context": "/contexts/TripDetail",
    "@id": `/trips/${getTripId()}/detail`,
    "@type": "TripDetail",
    id: getTripId(),
    title: "Tour de l'Ardeche",
    sourceUrl: "https://www.komoot.com/fr-fr/tour/2795080048",
    startDate: "2026-06-01T00:00:00+00:00",
    endDate: "2026-06-03T00:00:00+00:00",
    fatigueFactor: 0.8,
    elevationPenalty: 100,
    maxDistancePerDay: 80,
    averageSpeed: 15,
    ebikeMode: false,
    departureHour: 8,
    enabledAccommodationTypes: ["hotel", "camp_site"],
    isLocked: false,
    computationStatus: {
      route: "done",
      stages: "done",
      weather: "done",
      terrain: "done",
      accommodations: "done",
    },
    stages: [
      {
        dayNumber: 1,
        distance: 72.5,
        elevation: 1180,
        elevationLoss: 920,
        startPoint: { lat: 44.735, lon: 4.598, ele: 280 },
        endPoint: { lat: 44.532, lon: 4.392, ele: 540 },
        geometry: [
          { lat: 44.735, lon: 4.598, ele: 280 },
          { lat: 44.532, lon: 4.392, ele: 540 },
        ],
        label: null,
        isRestDay: false,
        weather: {
          icon: "02d",
          description: "Partly cloudy",
          tempMin: 14,
          tempMax: 26,
          windSpeed: 12,
          windDirection: "NO",
          precipitationProbability: 10,
          humidity: 65,
          comfortIndex: 78,
          relativeWindDirection: "crosswind",
        },
        alerts: [],
        pois: [],
        accommodations: [],
        selectedAccommodation: null,
        events: [],
      },
      {
        dayNumber: 2,
        distance: 63.2,
        elevation: 870,
        elevationLoss: 1050,
        startPoint: { lat: 44.532, lon: 4.392, ele: 540 },
        endPoint: { lat: 44.295, lon: 4.087, ele: 360 },
        geometry: [
          { lat: 44.532, lon: 4.392, ele: 540 },
          { lat: 44.295, lon: 4.087, ele: 360 },
        ],
        label: null,
        isRestDay: false,
        weather: null,
        alerts: [],
        pois: [],
        accommodations: [],
        selectedAccommodation: null,
        events: [],
      },
    ],
  };
}

/**
 * Open the planner welcome screen, submit a valid Komoot URL and wait until the
 * planner has navigated to `/trips/[id]` with the title visible. Mirrors the
 * `submitUrl` fixtures used by the mocked/recette suites.
 */
async function submitUrlAndAwaitTrip(page: Page): Promise<void> {
  await page.goto("/");
  await page.waitForLoadState("networkidle");
  await expandLinkCard(page);
  const input = page.getByTestId("magic-link-input");
  await input.fill("https://www.komoot.com/fr-fr/tour/2795080048");
  await input.press("Enter");
  await page.waitForURL(/\/trips\//, { timeout: 10000 });
  await expect(
    page.getByTestId("trip-title-skeleton").or(page.getByTestId("trip-title")),
  ).toBeVisible({ timeout: 5000 });
}
