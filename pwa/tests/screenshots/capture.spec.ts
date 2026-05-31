/**
 * Documentation screenshot capture — NOT part of the assertion suite.
 *
 * Regenerates the documentation screenshots from the mocked Playwright harness
 * (a deterministic demo trip — no real backend, OSM, weather or Ollama). It is
 * excluded from `make test-e2e` via `testIgnore: ["**\/screenshots\/**"]` in
 * playwright.config.ts and is meant to be run explicitly with `make screenshots`,
 * which mounts the repository root so the README assets (living outside `pwa/`)
 * are writable.
 *
 * Outputs:
 *   docs/assets/screenshots/desktop-split-view.png  — README, desktop split view
 *   docs/assets/screenshots/mobile-timeline.png     — README, mobile timeline
 *   pwa/public/images/screenshot-map.jpg            — landing carousel (16:9)
 *   pwa/public/images/screenshot-stage.jpg          — landing carousel (16:9)
 *   pwa/public/images/screenshot-analysis.jpg       — landing carousel (16:9)
 *
 * Map tiles (MapLibre) load over the network, so a short settle delay lets them
 * paint before capture; review the generated images by eye. Framings rely only
 * on stable roadbook test ids (view-mode-*, split-view-container, stage-card-N),
 * not on the top bar, so they survive UI chrome changes.
 */
import fs from "node:fs";
import path from "node:path";
import { type Page } from "@playwright/test";
import { test } from "../fixtures/base.fixture";
import { tripReadyEventWithStageAiAnalysis } from "../fixtures/mock-data";

// `make screenshots` runs with cwd = <repo>/pwa and mounts the repo root, so the
// README assets directory (one level above pwa/) is reachable and writable.
const PWA_ROOT = process.cwd();
const README_DIR = path.resolve(PWA_ROOT, "../docs/assets/screenshots");
const LANDING_DIR = path.resolve(PWA_ROOT, "public/images");

const DESKTOP = { width: 1440, height: 900 } as const;
const MOBILE = { width: 390, height: 844 } as const;
// 16:9 crop for the landing carousel (rendered with `aspect-video object-cover`).
const CLIP_16_9 = { x: 0, y: 0, width: 1440, height: 810 } as const;
const JPEG_QUALITY = 82;

/** Let map tiles and lazily-rendered panels settle before capturing. */
async function settle(page: Page, ms = 1500): Promise<void> {
  await page.waitForLoadState("networkidle");
  await page.waitForTimeout(ms);
}

test.beforeAll(() => {
  fs.mkdirSync(README_DIR, { recursive: true });
  fs.mkdirSync(LANDING_DIR, { recursive: true });
});

test.describe("desktop", () => {
  test.use({ viewport: { ...DESKTOP } });

  test("README split view + landing map slide", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    const toggle = mockedPage.getByTestId("view-mode-toggle");
    await toggle.getByTestId("view-mode-split").click();
    await mockedPage.getByTestId("split-view-container").waitFor();
    // Toggling to split re-mounts the map; give the CARTO tiles time to paint.
    await settle(mockedPage, 5000);

    await mockedPage.screenshot({
      path: path.join(README_DIR, "desktop-split-view.png"),
    });
    await mockedPage.screenshot({
      path: path.join(LANDING_DIR, "screenshot-map.jpg"),
      type: "jpeg",
      quality: JPEG_QUALITY,
      clip: { ...CLIP_16_9 },
    });
  });

  test("landing stage-detail slide", async ({ createFullTrip, mockedPage }) => {
    await createFullTrip();
    await mockedPage.getByTestId("stage-card-1").scrollIntoViewIfNeeded();
    await settle(mockedPage);
    await mockedPage.screenshot({
      path: path.join(LANDING_DIR, "screenshot-stage.jpg"),
      type: "jpeg",
      quality: JPEG_QUALITY,
      clip: { ...CLIP_16_9 },
    });
  });

  test("landing analysis slide (AI summary + alerts)", async ({
    createFullTrip,
    injectEvent,
    mockedPage,
  }) => {
    await createFullTrip();
    // Overlay the LLaMA pass-1 analysis (narrative + insights + mixed-severity
    // alerts) on stage 1 so the slide showcases the alert engine and AI summary.
    await injectEvent(tripReadyEventWithStageAiAnalysis());
    await mockedPage.getByTestId("stage-card-1").scrollIntoViewIfNeeded();
    await settle(mockedPage);
    await mockedPage.screenshot({
      path: path.join(LANDING_DIR, "screenshot-analysis.jpg"),
      type: "jpeg",
      quality: JPEG_QUALITY,
      clip: { ...CLIP_16_9 },
    });
  });
});

test.describe("mobile", () => {
  test.use({ viewport: { ...MOBILE } });

  test("README mobile timeline", async ({ createFullTrip, mockedPage }) => {
    await createFullTrip(); // defaults to timeline-only below the 1024px breakpoint
    await settle(mockedPage);
    await mockedPage.screenshot({
      path: path.join(README_DIR, "mobile-timeline.png"),
    });
  });
});
