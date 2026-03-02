import { test, expect } from "@playwright/test";

const backendOrigin = process.env.API_BACKEND_ORIGIN ?? "http://php:8000";

test.describe("Integration smoke test", () => {
  test("backend accepts and processes a real Komoot tour", async ({ page }) => {
    test.slow();

    let capturedTripId: string | null = null;
    let postStatus: number | null = null;

    // Route API calls to the PHP backend and capture trip ID from response
    const backend = new URL(backendOrigin);
    await page.route(
      (url) =>
        url.pathname.startsWith("/trips") ||
        url.pathname.startsWith("/geocode") ||
        url.pathname.startsWith("/accommodations"),
      async (route) => {
        const req = route.request();
        const target = new URL(req.url());
        target.protocol = backend.protocol;
        target.hostname = backend.hostname;
        target.port = backend.port;

        // For POST /trips, intercept to capture trip ID and status
        if (req.method() === "POST" && target.pathname === "/trips") {
          const response = await route.fetch({ url: target.toString() });
          const body = await response.json();
          capturedTripId = body?.id ?? null;
          postStatus = response.status();
          return route.fulfill({ response });
        }

        return route.continue({ url: target.toString() });
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
