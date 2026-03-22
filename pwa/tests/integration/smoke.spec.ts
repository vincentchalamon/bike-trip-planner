import { test, expect } from "@playwright/test";

test.describe("Integration smoke test", () => {
  test("backend accepts and processes a real Komoot tour", async ({ page }) => {
    test.slow();

    let capturedTripId: string | null = null;
    let postStatus: number | null = null;

    // Intercept POST /trips to capture trip ID and status from the response
    await page.route(
      (url) => url.pathname === "/trips",
      async (route) => {
        if (route.request().method() === "POST") {
          const response = await route.fetch();
          const body = await response.json();
          capturedTripId = body?.id ?? null;
          postStatus = response.status();
          return route.fulfill({ response });
        }

        return route.continue();
      },
    );

    // Abort real Mercure SSE (computation events are tested in mocked tests)
    await page.route("**/.well-known/mercure*", (route) => route.abort());

    await page.goto("/");
    await page.waitForLoadState("networkidle");

    // Submit a real Komoot tour URL
    const input = page.getByTestId("magic-link-input");
    await input.fill("https://www.komoot.com/fr-fr/tour/2795080048");
    await input.press("Enter");

    // Trip title skeleton should appear (trip created successfully)
    await expect(
      page
        .getByTestId("trip-title-skeleton")
        .or(page.getByTestId("trip-title")),
    ).toBeVisible({ timeout: 30000 });

    // Verify backend accepted the trip (202 Accepted with a valid UUID)
    expect(postStatus).toBe(202);
    expect(capturedTripId).toBeTruthy();
    expect(capturedTripId).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/,
    );
  });
});
