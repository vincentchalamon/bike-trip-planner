import { test, expect } from "../fixtures/base.fixture";
import { mockAllApis } from "../fixtures/api-mocks";

test.describe("Card Selection — Acte 1 Préparation", () => {
  // Each test starts from a pristine page without the auto-expand side effect,
  // so that the collapsed default state can be observed.
  test.beforeEach(async ({ page }) => {
    await mockAllApis(page);
    await page.goto("/");
    await page.waitForLoadState("networkidle");
  });

  test("shows both active cards and the AI placeholder by default", async ({
    page,
  }) => {
    await expect(page.getByTestId("card-selection")).toBeVisible();
    await expect(page.getByTestId("card-link")).toBeVisible();
    await expect(page.getByTestId("card-gpx")).toBeVisible();
    await expect(page.getByTestId("card-ai")).toBeVisible();

    // No input fields are rendered while no card is selected
    await expect(page.getByTestId("magic-link-input")).toBeHidden();
    await expect(page.getByTestId("card-gpx-dropzone")).toBeHidden();
  });

  test("AI card is rendered but not clickable (coming soon)", async ({
    page,
  }) => {
    const aiCard = page.getByTestId("card-ai");
    await expect(aiCard).toHaveAttribute("data-disabled", "true");
    // The "Coming soon" badge is visible
    await expect(aiCard).toContainText("Bientôt disponible");
  });

  test("selecting Link card reveals URL input and hides GPX card", async ({
    page,
  }) => {
    await page.getByTestId("card-link").click();

    // Link card is expanded and URL input is visible
    await expect(page.getByTestId("card-link")).toHaveAttribute(
      "data-expanded",
      "true",
    );
    await expect(page.getByTestId("magic-link-input")).toBeVisible();

    // GPX and AI cards are collapsed / hidden
    await expect(page.getByTestId("card-gpx")).toBeHidden();
    await expect(page.getByTestId("card-ai")).toBeHidden();
  });

  test("selecting GPX card reveals drop zone and hides Link card", async ({
    page,
  }) => {
    await page.getByTestId("card-gpx").click();

    // GPX card is expanded and drop zone is visible
    await expect(page.getByTestId("card-gpx")).toHaveAttribute(
      "data-expanded",
      "true",
    );
    await expect(page.getByTestId("card-gpx-dropzone")).toBeVisible();
    await expect(page.getByTestId("gpx-file-input")).toBeAttached();

    // Link and AI cards are collapsed / hidden
    await expect(page.getByTestId("card-link")).toBeHidden();
    await expect(page.getByTestId("card-ai")).toBeHidden();
  });

  test("back button restores the default card grid", async ({ page }) => {
    await page.getByTestId("card-link").click();
    await expect(page.getByTestId("magic-link-input")).toBeVisible();

    await page.getByTestId("card-selection-back").click();
    await expect(page.getByTestId("card-link")).toBeVisible();
    await expect(page.getByTestId("card-gpx")).toBeVisible();
    await expect(page.getByTestId("card-ai")).toBeVisible();
    await expect(page.getByTestId("magic-link-input")).toBeHidden();
  });

  test("frontend URL validation flags invalid URLs", async ({ page }) => {
    await page.getByTestId("card-link").click();
    const input = page.getByTestId("magic-link-input");

    await input.fill("not-a-url");
    await input.press("Enter");
    await expect(page.getByTestId("card-link-error")).toContainText(
      "Veuillez entrer une URL valide.",
    );
  });

  test("frontend URL validation flags unsupported sources", async ({
    page,
  }) => {
    await page.getByTestId("card-link").click();
    const input = page.getByTestId("magic-link-input");

    await input.fill("https://example.com/route/123");
    await input.press("Enter");
    await expect(page.getByTestId("card-link-error")).toContainText(
      "Source non supportée",
    );
  });

  test("frontend URL validation accepts a valid Komoot tour URL", async ({
    page,
  }) => {
    await page.getByTestId("card-link").click();
    const input = page.getByTestId("magic-link-input");

    await input.fill("https://www.komoot.com/fr-fr/tour/2795080048");
    await input.press("Enter");
    // The request is accepted and the app navigates to /trips/{id}
    await page.waitForURL(/\/trips\//, { timeout: 5000 });
  });

  test("oversized GPX file blocks upload and shows error alert", async ({
    page,
  }) => {
    await page.getByTestId("card-gpx").click();

    // Track whether POST /trips is called — it must NOT be for an oversized file
    let uploadCalled = false;
    await page.route("**/trips", (route, request) => {
      if (request.method() === "POST") uploadCalled = true;
      return route.fallback();
    });

    const fileInput = page.getByTestId("gpx-file-input");
    // 31 MB buffer — exceeds the 30 MB frontend guard
    const oversizedBuffer = Buffer.alloc(31 * 1024 * 1024, 0x20);
    await fileInput.setInputFiles({
      name: "huge-route.gpx",
      mimeType: "application/gpx+xml",
      buffer: oversizedBuffer,
    });

    // File feedback still shows (user sees what they attempted to upload)
    await expect(page.getByTestId("card-gpx-file-name")).toContainText(
      "huge-route.gpx",
    );
    // Size-limit error alert is displayed inside the GPX card
    await expect(page.getByTestId("card-gpx").getByRole("alert")).toBeVisible();
    // And the upload endpoint was never hit
    expect(uploadCalled).toBe(false);
  });
});
