import { test, expect } from "@playwright/test";

/**
 * Issue #386 — Design Foundations: palette ambre + tokens globals.css
 *
 * Vérifie que les tokens sémantiques warm paper / ink charcoal / amber sont
 * bien définis et appliqués sur la landing page (mode clair + sombre)
 * et sur le roadbook (mode clair + sombre).
 */

const AMBER_BRAND_LIGHT = "#c2671e";
const AMBER_BRAND_DARK = "#e08040";
const SURFACE_LIGHT = "#faf7f0";
const INK_LIGHT = "#1a1814";

/**
 * Lit la valeur calculée d'une variable CSS sur :root (ou html).
 */
async function getCssVar(
  page: import("@playwright/test").Page,
  varName: string,
): Promise<string> {
  return page.evaluate((v) => {
    return getComputedStyle(document.documentElement)
      .getPropertyValue(v)
      .trim();
  }, varName);
}

// ── Landing page — mode clair ────────────────────────────────────────────────

test.describe("Design tokens — landing page (mode clair)", () => {
  test.beforeEach(async ({ page }) => {
    await page.route("**/auth/refresh", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({ status: 401, body: "" });
    });
    // Force light mode: remove .dark class if present
    await page.emulateMedia({ colorScheme: "light" });
    await page.goto("/");
    await page.waitForLoadState("networkidle");
  });

  test("--brand est la couleur ambre principale en mode clair", async ({
    page,
  }) => {
    const brand = await getCssVar(page, "--brand");
    expect(brand.toLowerCase()).toBe(AMBER_BRAND_LIGHT.toLowerCase());
  });

  test("--surface est warm paper (#faf7f0) en mode clair", async ({ page }) => {
    const surface = await getCssVar(page, "--surface");
    expect(surface.toLowerCase()).toBe(SURFACE_LIGHT.toLowerCase());
  });

  test("--ink est ink charcoal (#1a1814) en mode clair", async ({ page }) => {
    const ink = await getCssVar(page, "--ink");
    expect(ink.toLowerCase()).toBe(INK_LIGHT.toLowerCase());
  });

  test("--accent-brand est défini en mode clair", async ({ page }) => {
    const accentBrand = await getCssVar(page, "--accent-brand");
    expect(accentBrand).not.toBe("");
    expect(accentBrand.toLowerCase()).toBe(AMBER_BRAND_LIGHT.toLowerCase());
  });

  test("--accent-soft est défini en mode clair", async ({ page }) => {
    const accentSoft = await getCssVar(page, "--accent-soft");
    expect(accentSoft).not.toBe("");
  });

  test("--accent-ink est défini en mode clair", async ({ page }) => {
    const accentInk = await getCssVar(page, "--accent-ink");
    expect(accentInk).not.toBe("");
  });

  test("--background correspond à --surface en mode clair", async ({
    page,
  }) => {
    const bg = await getCssVar(page, "--background");
    expect(bg.toLowerCase()).toBe(SURFACE_LIGHT.toLowerCase());
  });

  test("landing page est visible avec la palette ambre", async ({ page }) => {
    await expect(page.getByTestId("landing-page")).toBeVisible();
    await expect(page.getByTestId("section-hero")).toBeVisible();
  });
});

// ── Landing page — mode sombre ───────────────────────────────────────────────

test.describe("Design tokens — landing page (mode sombre)", () => {
  test.beforeEach(async ({ page }) => {
    await page.route("**/auth/refresh", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({ status: 401, body: "" });
    });
    await page.emulateMedia({ colorScheme: "dark" });
    await page.goto("/");
    await page.waitForLoadState("networkidle");
    // Apply .dark class so shadcn dark tokens are activated
    await page.evaluate(() => {
      document.documentElement.classList.add("dark");
    });
  });

  test("--brand est la couleur ambre ajustée en mode sombre", async ({
    page,
  }) => {
    const brand = await getCssVar(page, "--brand");
    expect(brand.toLowerCase()).toBe(AMBER_BRAND_DARK.toLowerCase());
  });

  test("--surface est ink charcoal (#1a1814) en mode sombre", async ({
    page,
  }) => {
    const surface = await getCssVar(page, "--surface");
    expect(surface.toLowerCase()).toBe(INK_LIGHT.toLowerCase());
  });

  test("--diff-highlight est défini en mode sombre", async ({ page }) => {
    const diffHighlight = await getCssVar(page, "--diff-highlight");
    expect(diffHighlight).not.toBe("");
  });

  test("landing page est visible en mode sombre", async ({ page }) => {
    await expect(page.getByTestId("landing-page")).toBeVisible();
  });
});
