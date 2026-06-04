import type { Page, Locator } from "@playwright/test";
import {
  test as visualTest,
  expect as visualExpect,
} from "./support/visual.fixture";

/**
 * App-vs-design comparison test (Sprint 35.3 — Ordre 4).
 *
 * Unlike the screenshot baselines, this suite is **assertional**: for each
 * screen of the recette inventory (`docs/recette/01-inventaire-ecrans.md`) it
 * encodes the elements the design manifest
 * (`docs/recette/03-manifeste-elements.md`) expects, with their **region**
 * (header / sidebar-L / sidebar-R / main / footer / overlay) and asserts that
 * each is **visible** AND in the right region of the layout. This is the
 * automation of the per-screen verdict (DoD: "chaque écran du manifeste a un
 * verdict").
 *
 * No screenshot baselines are needed — only the app running.
 *
 * Method & honesty notes:
 *  - Elements are targeted by **testid / role / aria**, never by FR text
 *    (the app is bilingual). Only elements that exist in the app with a stable
 *    selector are encoded; the manifest also lists design-only chrome (e.g. a
 *    left "Sommaire" rail on /faq, /legal, /privacy, /account/settings) that the
 *    current app renders as a single column — those layout divergences are a
 *    human side-by-side check in the manual recette, not automatable here.
 *  - Region is checked with a viewport-relative heuristic (see
 *    {@link regionOf}); approximate by design, matching the "position
 *    approximative" convention of the manifest.
 *  - The 1280px desktop design drives the region semantics (sidebars), so the
 *    suite runs on desktop projects only and is skipped on tablet/mobile combos
 *    where columns collapse.
 */

type Region =
  | "header"
  | "sidebar-L"
  | "sidebar-R"
  | "main"
  | "footer"
  | "overlay";

interface ExpectedElement {
  /** Human-readable name, used in the failure report. */
  name: string;
  /** Resolve the element on the page (testid / role / aria — never raw text). */
  locator: (page: Page) => Locator;
  /** Region the design places this element in. */
  region: Region;
}

interface ScreenSpec {
  /** Inventory screen name. */
  name: string;
  /** Navigate + reach the screen's target state. */
  goto: (page: Page, ctx: ScreenContext) => Promise<void>;
  /** Elements expected by the design manifest (top-to-bottom per region). */
  elements: ExpectedElement[];
}

interface ScreenContext {
  gotoRoadbook: () => Promise<void>;
}

const byTestId =
  (id: string) =>
  (page: Page): Locator =>
    page.getByTestId(id);

/**
 * Resolve which region a bounding box falls into, relative to the viewport
 * width and the full scrollable page height. Approximate by design:
 *  - header: starts within the top 20% of the page (or the first 140px);
 *  - footer: its bottom edge sits past 70% of the page height;
 *  - sidebar-L / sidebar-R: horizontal centre in the left / right ~45% band;
 *  - main: the central catch-all.
 */
async function regionOf(page: Page, locator: Locator): Promise<Region[]> {
  const box = await locator.boundingBox();
  if (!box) return [];
  const viewport = page.viewportSize() ?? { width: 1280, height: 860 };
  const pageHeight = await page.evaluate(
    () => document.documentElement.scrollHeight,
  );
  const centerX = box.x + box.width / 2;
  const top = box.y;
  const bottom = box.y + box.height;

  const regions: Region[] = [];
  // Vertical bands.
  if (top < Math.max(140, pageHeight * 0.2)) regions.push("header");
  if (bottom > pageHeight * 0.7) regions.push("footer");
  // Horizontal bands (sidebars).
  if (centerX < viewport.width * 0.45) regions.push("sidebar-L");
  if (centerX > viewport.width * 0.55) regions.push("sidebar-R");
  // Central catch-all is always allowed for `main`/`overlay` checks.
  regions.push("main");
  return regions;
}

/**
 * Assert every expected element is visible and in (or compatible with) its
 * design region. Collects all problems and fails once with a per-screen
 * report, so a single run yields the full verdict for the screen.
 */
async function assertManifest(page: Page, screen: ScreenSpec): Promise<void> {
  const problems: string[] = [];
  for (const el of screen.elements) {
    const locator = el.locator(page).first();
    const visible = await locator.isVisible().catch(() => false);
    if (!visible) {
      problems.push(`- "${el.name}": not visible (expected in ${el.region})`);
      continue;
    }
    // `overlay` and `main` need no positional constraint beyond visibility.
    if (el.region === "overlay" || el.region === "main") continue;
    const regions = await regionOf(page, locator);
    if (!regions.includes(el.region)) {
      problems.push(
        `- "${el.name}": visible but in [${regions.join(", ") || "none"}], expected ${el.region}`,
      );
    }
  }
  visualExpect(
    problems,
    `Manifest verdict for "${screen.name}":\n${problems.join("\n")}`,
  ).toEqual([]);
}

// ---------------------------------------------------------------------------
// Per-screen specs (inventory order).
// ---------------------------------------------------------------------------

const SCREENS: ScreenSpec[] = [
  {
    // 1. Accueil — / (dual-state). The mock chain auto-authenticates, so / is
    // the planner dashboard (CardSelection + footer), not the anon landing.
    // The anon landing hero/sections are baselined by `pages.spec.ts > landing`.
    name: "home dashboard (/)",
    goto: async (page) => {
      await page.goto("/");
      await page.waitForLoadState("networkidle");
    },
    elements: [
      {
        name: "card selection (import sources)",
        locator: byTestId("card-selection"),
        region: "main",
      },
      {
        name: "footer FAQ link",
        locator: byTestId("footer-faq-link"),
        region: "footer",
      },
    ],
  },
  {
    // 2. Connexion — /login
    name: "login (/login)",
    goto: async (page) => {
      await page.goto("/login");
      await page.waitForLoadState("networkidle");
    },
    elements: [
      {
        name: "login card (email + magic link)",
        locator: byTestId("login-card"),
        region: "main",
      },
      {
        name: "early-access banner",
        locator: byTestId("early-access-banner"),
        region: "main",
      },
      {
        name: "footer FAQ link",
        locator: byTestId("footer-faq-link"),
        region: "footer",
      },
    ],
  },
  {
    // 3. Mes voyages — /trips (populated)
    name: "trips list (/trips)",
    goto: async (page) => {
      await page.route(
        (url) => url.pathname === "/trips",
        (route, request) => {
          if (request.method() !== "GET") return route.fallback();
          return route.fulfill({
            status: 200,
            contentType: "application/ld+json",
            body: JSON.stringify(tripsCollection(4)),
          });
        },
      );
      await page.goto("/trips");
      await visualExpect(page.getByTestId("trips-grid")).toBeVisible({
        timeout: 10000,
      });
    },
    elements: [
      {
        name: "new-trip button",
        locator: byTestId("new-trip-button"),
        region: "header",
      },
      {
        name: "trips grid (cards)",
        locator: byTestId("trips-grid"),
        region: "main",
      },
    ],
  },
  {
    // 4. Nouveau voyage (wizard) — /trips/new step 1 (preparation)
    name: "wizard step 1 (/trips/new)",
    goto: async (page) => {
      await page.goto("/trips/new");
      await visualExpect(page.getByTestId("wizard-trip-new")).toBeVisible({
        timeout: 10000,
      });
    },
    elements: [
      {
        name: "wizard stepper",
        locator: byTestId("wizard-stepper"),
        region: "main",
      },
      {
        name: "card selection (URL / GPX / IA)",
        locator: byTestId("card-selection"),
        region: "main",
      },
    ],
  },
  {
    // 5. Détail voyage — /trips/[id] (roadbook)
    name: "roadbook (/trips/[id])",
    goto: async (page, ctx) => {
      await ctx.gotoRoadbook();
    },
    elements: [
      { name: "top bar", locator: byTestId("top-bar"), region: "header" },
      {
        name: "share button (top bar)",
        locator: byTestId("share-button"),
        region: "header",
      },
      {
        name: "timeline sidebar (stages)",
        locator: byTestId("timeline-sidebar"),
        region: "sidebar-L",
      },
      {
        name: "roadbook master-detail",
        locator: byTestId("roadbook-master-detail"),
        region: "main",
      },
    ],
  },
  {
    // 10. FAQ — /faq
    name: "faq (/faq)",
    goto: async (page) => {
      await page.goto("/faq");
      await page.waitForLoadState("networkidle");
    },
    elements: [
      {
        name: "back-to-home link",
        locator: byTestId("faq-back-link"),
        region: "header",
      },
      {
        name: "FAQ heading",
        locator: (page) => page.getByRole("heading", { level: 1 }),
        region: "main",
      },
    ],
  },
  {
    // 11. Mentions légales — /legal
    name: "legal (/legal)",
    goto: async (page) => {
      await page.goto("/legal");
      await page.waitForLoadState("networkidle");
    },
    elements: [
      {
        name: "legal heading",
        locator: (page) => page.getByRole("heading", { level: 1 }),
        region: "main",
      },
    ],
  },
  {
    // 12. Confidentialité — /privacy
    name: "privacy (/privacy)",
    goto: async (page) => {
      await page.goto("/privacy");
      await page.waitForLoadState("networkidle");
    },
    elements: [
      {
        name: "privacy heading",
        locator: (page) => page.getByRole("heading", { level: 1 }),
        region: "main",
      },
    ],
  },
  {
    // 7. Paramètres du compte — /account/settings
    name: "account settings (/account/settings)",
    goto: async (page) => {
      await page.goto("/account/settings");
      await visualExpect(page.getByTestId("account-settings-page")).toBeVisible(
        { timeout: 10000 },
      );
    },
    elements: [
      {
        name: "account section (email)",
        locator: byTestId("account-section"),
        region: "main",
      },
      {
        name: "preferences section (lang + theme)",
        locator: byTestId("preferences-section"),
        region: "main",
      },
      {
        name: "data section (GDPR export)",
        locator: byTestId("data-section"),
        region: "main",
      },
      {
        name: "danger zone (delete account)",
        locator: byTestId("danger-zone-section"),
        region: "main",
      },
    ],
  },
  {
    // 13. Page non trouvée — not-found.tsx
    name: "404 (not-found)",
    goto: async (page) => {
      await page.goto("/route-inexistante");
      await visualExpect(page.getByTestId("not-found-page")).toBeVisible({
        timeout: 10000,
      });
    },
    elements: [
      {
        name: "404 title",
        locator: byTestId("not-found-title"),
        region: "main",
      },
      {
        name: "home CTA",
        locator: byTestId("not-found-home-link"),
        region: "main",
      },
    ],
  },
];

visualTest.describe("app vs design — manifest verdict", () => {
  for (const screen of SCREENS) {
    visualTest(screen.name, async ({ visualPage, gotoRoadbook }, testInfo) => {
      // Region semantics (sidebars) follow the 1280px desktop design; skip the
      // tablet/mobile combos where columns collapse.
      const width = testInfo.project.use.viewport?.width ?? 0;
      visualTest.skip(
        width < 1024,
        "Manifest region checks target the desktop (>=1024px) design layout.",
      );
      await screen.goto(visualPage, { gotoRoadbook });
      await assertManifest(visualPage, screen);
    });
  }
});

/** A populated Hydra collection of `count` trips for the /trips list. */
function tripsCollection(count: number) {
  const member = Array.from({ length: count }, (_, i) => ({
    "@id": `/trips/trip-${i + 1}`,
    "@type": "Trip",
    id: `trip-${i + 1}`,
    title: `Tour ${i + 1}`,
    startDate: "2026-06-01T00:00:00+00:00",
    endDate: "2026-06-03T00:00:00+00:00",
    totalDistance: 187300,
    stageCount: 3,
    createdAt: "2026-05-01T00:00:00+00:00",
    updatedAt: "2026-05-02T00:00:00+00:00",
    status: i % 2 === 0 ? "analyzed" : "draft",
  }));
  return {
    "@context": "/contexts/Trip",
    "@id": "/trips",
    "@type": "hydra:Collection",
    "hydra:totalItems": count,
    "hydra:member": member,
    member,
    totalItems: count,
  };
}
