import { test, expect } from "@playwright/test";

test.describe("Integration smoke test", () => {
  test("backend accepts and processes a real Komoot tour", async ({ page }) => {
    test.slow();

    let capturedTripId: string | null = null;
    let postStatus: number | null = null;
    let postError: string | null = null;

    // Log all console messages for debugging
    page.on("console", (msg) => {
      if (msg.type() === "error" || msg.type() === "warning") {
        console.log(`[browser ${msg.type()}] ${msg.text()}`);
      }
    });

    // Log network failures
    page.on("requestfailed", (request) => {
      console.log(
        `[network FAIL] ${request.method()} ${request.url()} — ${request.failure()?.errorText}`,
      );
    });

    // Intercept POST /trips to capture trip ID and status from the response
    await page.route(
      (url) => url.pathname === "/trips",
      async (route) => {
        if (route.request().method() === "POST") {
          try {
            const response = await route.fetch();
            postStatus = response.status();
            const body = await response.json();
            capturedTripId = body?.id ?? null;
            console.log(
              `[smoke] POST /trips => ${postStatus}, tripId=${capturedTripId}`,
            );
            return route.fulfill({ response });
          } catch (e) {
            postError = String(e);
            console.log(`[smoke] POST /trips FETCH ERROR: ${postError}`);
            return route.abort();
          }
        }

        return route.continue();
      },
    );

    // Abort real Mercure SSE (computation events are tested in mocked tests)
    await page.route("**/.well-known/mercure*", (route) => route.abort());

    await page.goto("/");
    await page.waitForLoadState("networkidle");
    console.log("[smoke] Page loaded, submitting Komoot URL...");

    // Submit a real Komoot tour URL
    const input = page.getByTestId("magic-link-input");
    await input.fill("https://www.komoot.com/fr-fr/tour/2795080048");
    await input.press("Enter");
    console.log("[smoke] URL submitted, waiting for trip title skeleton...");

    // Wait a bit then capture page state for debugging
    await page.waitForTimeout(5000);
    console.log(
      `[smoke] After 5s — postStatus=${postStatus}, tripId=${capturedTripId}, postError=${postError}`,
    );

    // Take a screenshot for debugging
    await page.screenshot({ path: "test-results/smoke-debug.png" });
    console.log("[smoke] Debug screenshot saved to test-results/smoke-debug.png");

    // Log the page HTML around the trip area
    const bodyHtml = await page.locator("main").innerHTML().catch(() => "N/A");
    console.log(
      `[smoke] Main HTML (first 500 chars): ${bodyHtml.substring(0, 500)}`,
    );

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
