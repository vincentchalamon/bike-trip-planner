import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  tripCompleteEvent,
  validationErrorEvent,
  computationErrorEvent,
} from "../fixtures/mock-data";

test.describe("Error handling", () => {
  test("shows error toast on API 400", async ({ page }) => {
    const { mockAllApis } = await import("../fixtures/api-mocks");
    await mockAllApis(page, {
      postTripStatus: 400,
      postTripBody: {
        "@type": "hydra:Error",
        "hydra:title": "Bad Request",
        detail: "URL source non supportee.",
      },
    });
    await page.goto("/");
    await page.waitForLoadState("networkidle");
    const input = page.getByTestId("magic-link-input");
    await input.fill("https://www.komoot.com/fr-fr/tour/12345");
    await input.press("Enter");
    await expect(page.getByText("URL source non supportee.")).toBeVisible({
      timeout: 5000,
    });
  });

  test("shows toast on validation_error SSE", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(validationErrorEvent());
    await expect(
      mockedPage.getByText("URL source invalide ou inaccessible."),
    ).toBeVisible({ timeout: 5000 });
  });

  test("shows toast on computation_error SSE", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(computationErrorEvent(false));
    await expect(
      mockedPage.getByText("Service meteo temporairement indisponible."),
    ).toBeVisible({ timeout: 5000 });
  });

  test("shows client-side validation for invalid URL", async ({
    mockedPage,
  }) => {
    const input = mockedPage.getByTestId("magic-link-input");
    await input.fill("not-a-valid-url");
    await input.press("Enter");
    await expect(
      mockedPage.getByText("Veuillez entrer une URL valide."),
    ).toBeVisible();
  });

  test("reverts stages after failed deletion", async ({ page }) => {
    const { mockAllApis } = await import("../fixtures/api-mocks");
    await mockAllApis(page, { deleteStageFail: true });
    await page.route("**/.well-known/mercure*", (route) => route.abort());
    await page.goto("/");
    await page.waitForLoadState("networkidle");
    const input = page.getByTestId("magic-link-input");
    await input.fill("https://www.komoot.com/fr-fr/tour/12345");
    await input.press("Enter");
    // Wait for navigation to /trips/[id] and for TripPlanner to mount
    await page.waitForURL(/\/trips\//, { timeout: 5000 });
    await expect(
      page
        .getByTestId("trip-title-skeleton")
        .or(page.getByTestId("trip-title")),
    ).toBeVisible({ timeout: 5000 });
    const { injectSseSequence } = await import("../fixtures/sse-helpers");
    const { fullTripEventSequence } = await import("../fixtures/mock-data");
    await injectSseSequence(page, fullTripEventSequence());
    await expect(page.getByTestId("stage-card-3")).toBeVisible({
      timeout: 10000,
    });
    // Try to delete stage 2
    await page.getByTestId("delete-stage-2").click();
    // Stage should reappear after API failure + revert
    await expect(page.getByTestId("stage-card-3")).toBeVisible({
      timeout: 5000,
    });
    // Error toast should appear
    await expect(page.getByText("Impossible de supprimer")).toBeVisible({
      timeout: 5000,
    });
  });
});
