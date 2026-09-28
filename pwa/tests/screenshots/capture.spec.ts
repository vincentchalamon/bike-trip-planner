/**
 * Documentation screenshot capture — NOT part of the assertion suite.
 *
 * Regenerates the documentation screenshots from the mocked Playwright harness
 * (a deterministic demo trip — no real backend, OSM or weather). It is
 * excluded from `make test-e2e` via `testIgnore: ["**\/screenshots\/**"]` in
 * playwright.config.ts and is meant to be run explicitly with `make screenshots`,
 * which mounts the repository root so the README assets (living outside `pwa/`)
 * are writable.
 *
 * Outputs:
 *   docs/assets/screenshots/desktop-split-view.png  — README, desktop split view
 *   docs/assets/screenshots/mobile-timeline.png     — README, mobile timeline
 *   docs/assets/screenshots/new-trip.png            — tutorial, trip creation
 *   docs/assets/screenshots/settings-panel.png      — tutorial, settings drawer
 *   docs/assets/screenshots/share-modal.png         — tutorial, share dialog
 *   pwa/public/images/screenshot-map.jpg            — landing carousel (16:9)
 *   pwa/public/images/screenshot-stage.jpg          — landing carousel (16:9)
 *   pwa/public/images/screenshot-analysis.jpg       - landing carousel (16:9)
 *
 * Map tiles (MapLibre) load over the network, so a short settle delay lets them
 * paint before capture; review the generated images by eye. Framings rely only
 * on stable roadbook test ids (view-mode-*, split-view-container, stage-card-N,
 * stage-alerts, stage-difficulty-composed), not on the top bar, so they survive
 * UI chrome changes.
 */
import fs from "node:fs";
import path from "node:path";
import { type Page } from "@playwright/test";
import { test } from "../fixtures/base.fixture";

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
  // `make start-dev` serves a dev build: hide the Next.js dev-tools badge, and
  // the transient toasts that would cover the header.
  await page.addStyleTag({
    content:
      "nextjs-portal, [data-sonner-toaster] { display: none !important; }",
  });
  await page.waitForTimeout(ms);
}

test.beforeAll(() => {
  fs.mkdirSync(README_DIR, { recursive: true });
  fs.mkdirSync(LANDING_DIR, { recursive: true });
});

// The documentation is English-only, while the landing carousel keeps the
// app's default locale (French): only the docs captures switch the next-intl
// locale cookie.
const ENGLISH = {
  locale: "en-GB",
  storageState: {
    cookies: [
      {
        name: "locale",
        value: "en",
        domain: "localhost",
        path: "/",
        expires: -1,
        httpOnly: false,
        secure: false,
        sameSite: "Lax" as const,
      },
    ],
    origins: [],
  },
};

async function showSplitView(page: Page): Promise<void> {
  const toggle = page.getByTestId("view-mode-toggle");
  await toggle.getByTestId("view-mode-split").click();
  await page.getByTestId("split-view-container").waitFor();
  // Toggling to split re-mounts the map; give the CARTO tiles time to paint.
  await settle(page, 5000);
}

test.describe("docs desktop", () => {
  test.use({ viewport: { ...DESKTOP }, ...ENGLISH });

  test("split view", async ({ createFullTrip, mockedPage }) => {
    await createFullTrip();
    await showSplitView(mockedPage);
    await mockedPage.screenshot({
      path: path.join(README_DIR, "desktop-split-view.png"),
    });
  });

  test("new trip", async ({ mockedPage }) => {
    await mockedPage
      .getByTestId("magic-link-input")
      .fill("https://www.komoot.com/fr-fr/tour/2795080048");
    await settle(mockedPage);
    await mockedPage.screenshot({
      path: path.join(README_DIR, "new-trip.png"),
      fullPage: true,
    });
  });

  test("settings panel", async ({ createFullTrip, mockedPage }) => {
    await createFullTrip();
    await mockedPage.getByRole("button", { name: "Open settings" }).click();
    await mockedPage.getByRole("dialog").getByText("Rider profile").waitFor();
    await settle(mockedPage);
    await mockedPage.screenshot({
      path: path.join(README_DIR, "settings-panel.png"),
    });
  });

  test("share dialog", async ({ createFullTrip, mockedPage }) => {
    await createFullTrip();
    await mockedPage.getByTestId("share-button").click();
    await mockedPage.getByTestId("share-infographic-canvas").waitFor();
    await settle(mockedPage);
    await mockedPage.screenshot({
      path: path.join(README_DIR, "share-modal.png"),
    });
  });
});

test.describe("docs mobile", () => {
  test.use({ viewport: { ...MOBILE }, ...ENGLISH });

  test("timeline", async ({ createFullTrip, mockedPage }) => {
    await createFullTrip(); // defaults to timeline-only below the 1024px breakpoint
    await settle(mockedPage);
    await mockedPage.screenshot({
      path: path.join(README_DIR, "mobile-timeline.png"),
    });
  });
});

test.describe("landing", () => {
  test.use({ viewport: { ...DESKTOP } });

  test("map slide", async ({ createFullTrip, mockedPage }) => {
    await createFullTrip();
    await showSplitView(mockedPage);
    await mockedPage.screenshot({
      path: path.join(LANDING_DIR, "screenshot-map.jpg"),
      type: "jpeg",
      quality: JPEG_QUALITY,
      clip: { ...CLIP_16_9 },
    });
  });

  test("stage-detail slide", async ({ createFullTrip, mockedPage }) => {
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

  // Split view renders the full 3-bar difficulty gauge; the compact timeline
  // cell only shows the overall pill. Day 1 carries the terrain alert of
  // fullTripEventSequence(), so both halves of the analysis are on screen.
  test("analysis slide", async ({ createFullTrip, mockedPage }) => {
    await createFullTrip();
    await showSplitView(mockedPage);
    const stage = mockedPage.getByTestId("stage-card-1");
    await stage.getByTestId("stage-alerts").waitFor();
    await stage
      .getByTestId("stage-difficulty-composed")
      .evaluate((el) => el.scrollIntoView({ block: "start" }));
    await settle(mockedPage);
    await mockedPage.screenshot({
      path: path.join(LANDING_DIR, "screenshot-analysis.jpg"),
      type: "jpeg",
      quality: JPEG_QUALITY,
      clip: { ...CLIP_16_9 },
    });
  });
});
