import { test, expect } from "@playwright/test";
import { seededShare } from "../fixtures/shared-trip-seed";

/**
 * `/s/{code}` against the REAL backend, on the share seeded by the Playwright
 * globalSetup (`tests/fixtures/shared-trip-seed.ts`). The page fetches the share
 * server-side, so it cannot be mocked with `page.route()`. The detailed
 * rendering is covered by the vitest component tests
 * (`src/app/s/[code]/shared-trip-page.test.tsx`); CI has no weather or
 * accommodation data, so this only checks the real path: rendering, metadata
 * and the unknown-code state.
 */
// Read in the test body: the globalSetup exports the share through env vars,
// which only the workers are guaranteed to see.
const share = () => seededShare()!;

const escapeRegExp = (text: string) =>
  text.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

test.describe("/s/[code] page (real backend)", () => {
  test.beforeEach(() => {
    test.skip(
      !seededShare(),
      "No seeded share: set E2E_JWT (see shared-trip-seed.ts).",
    );
  });

  test("renders the shared trip with its stages", async ({ page }) => {
    await page.goto(`/s/${share().code}`);

    await expect(
      page.getByRole("heading", { name: share().title }),
    ).toBeVisible();
    await expect(page.getByTestId("read-only-banner")).toBeVisible();
    await expect(page.getByTestId("stage-card-1")).toBeVisible();
    await expect(page.getByTestId("stage-card-2")).toBeVisible();
  });

  test("exposes the share in its Open Graph metadata", async ({ page }) => {
    await page.goto(`/s/${share().code}`);

    await expect(page.locator('meta[property="og:title"]')).toHaveAttribute(
      "content",
      new RegExp(`^${escapeRegExp(share().title)} . Bike Trip Planner$`),
    );
    await expect(page.locator('meta[property="og:url"]')).toHaveAttribute(
      "content",
      new RegExp(`/s/${share().code}$`),
    );
    await expect(
      page.locator('meta[property="og:description"]'),
    ).toHaveAttribute("content", /\d+ km/);
  });
});

test("/s/[code] shows the not-found state for an unknown code", async ({
  page,
}) => {
  await page.goto("/s/unknown0");

  await expect(page.getByTestId("share-error")).toBeVisible();
  await expect(page.getByTestId("top-bar")).toBeVisible();
  await expect(page).toHaveTitle(/Voyage partagé/);
});
