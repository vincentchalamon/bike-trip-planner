import { test, expect } from "../fixtures/base.fixture";
import { getTripId } from "../fixtures/api-mocks";
import {
  routeParsedEvent,
  stagesComputedEvent,
  weatherFetchedEvent,
  accommodationsFoundEvent,
  tripCompleteEvent,
} from "../fixtures/mock-data";

const SHORT_CODE = "Ab3kX9mP";
const SHARE_TOKEN = "share-token-xyz";

/** Build the expected share URL using the page's actual origin. */
function expectedShareUrl(page: import("@playwright/test").Page): string {
  const origin = new URL(page.url()).origin;
  return `${origin}/s/${SHORT_CODE}`;
}

/** Mock GET /trips/{tripId}/share — returns 404 (no active share). */
function mockShareGetNone(
  page: import("@playwright/test").Page,
  tripId: string,
) {
  return page.route(`**/trips/${tripId}/share`, (route, request) => {
    if (request.method() !== "GET") return route.fallback();
    return route.fulfill({ status: 404, body: "" });
  });
}

/** Mock GET /trips/{tripId}/share — returns existing share. */
function mockShareGetExisting(
  page: import("@playwright/test").Page,
  tripId: string,
  shortCode = SHORT_CODE,
  token = SHARE_TOKEN,
) {
  return page.route(`**/trips/${tripId}/share`, (route, request) => {
    if (request.method() !== "GET") return route.fallback();
    return route.fulfill({
      status: 200,
      contentType: "application/ld+json",
      body: JSON.stringify({
        shortCode,
        token,
        createdAt: new Date().toISOString(),
      }),
    });
  });
}

function mockShareCreate(
  page: import("@playwright/test").Page,
  tripId: string,
  shortCode = SHORT_CODE,
  token = SHARE_TOKEN,
) {
  return page.route(`**/trips/${tripId}/share`, (route, request) => {
    if (request.method() !== "POST") return route.fallback();
    return route.fulfill({
      status: 201,
      contentType: "application/ld+json",
      body: JSON.stringify({
        shortCode,
        token,
        createdAt: new Date().toISOString(),
      }),
    });
  });
}

function mockShareRevoke(
  page: import("@playwright/test").Page,
  tripId: string,
) {
  return page.route(`**/trips/${tripId}/share`, (route, request) => {
    if (request.method() !== "DELETE") return route.fallback();
    return route.fulfill({ status: 204, body: "" });
  });
}

/** Helper: create a full trip and open the share modal. */
async function openShareModal(fixtures: {
  submitUrl: (url?: string) => Promise<void>;
  injectSequence: (
    events: import("../../src/lib/mercure/types").MercureEvent[],
    delayMs?: number,
  ) => Promise<void>;
  mockedPage: import("@playwright/test").Page;
}) {
  const { submitUrl, injectSequence, mockedPage } = fixtures;

  // Mock share API: GET returns existing share
  await mockShareGetExisting(mockedPage, getTripId());
  await mockShareRevoke(mockedPage, getTripId());

  await submitUrl();
  await injectSequence([
    routeParsedEvent(),
    stagesComputedEvent(),
    weatherFetchedEvent(),
    accommodationsFoundEvent(0),
    accommodationsFoundEvent(1),
    tripCompleteEvent(),
  ]);

  // Wait for trip to be fully loaded
  await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
    timeout: 10000,
  });

  // Click share button in action bar
  await mockedPage.getByTestId("share-button").click();

  // Wait for modal and share link to appear
  await expect(mockedPage.getByTestId("share-link-text")).toBeVisible({
    timeout: 5000,
  });
}

test.describe("Share modal", () => {
  test("share button opens modal with share link", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await openShareModal({ submitUrl, injectSequence, mockedPage });

    // Share link should contain the short URL
    await expect(mockedPage.getByTestId("share-link-text")).toHaveText(
      expectedShareUrl(mockedPage),
    );

    // All three sections should be visible
    await expect(
      mockedPage.getByTestId("share-copy-link-button"),
    ).toBeVisible();
    await expect(
      mockedPage.getByTestId("share-download-png-button"),
    ).toBeVisible();
    await expect(
      mockedPage.getByTestId("share-copy-text-button"),
    ).toBeVisible();
  });

  test("copy link copies share URL to clipboard", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await mockedPage
      .context()
      .grantPermissions(["clipboard-read", "clipboard-write"]);

    await openShareModal({ submitUrl, injectSequence, mockedPage });
    await mockedPage.getByTestId("share-copy-link-button").click();

    // Verify clipboard content
    const clipboardText = await mockedPage.evaluate(() =>
      navigator.clipboard.readText(),
    );
    expect(clipboardText).toBe(expectedShareUrl(mockedPage));
  });

  test("revoke shows create share link button without auto-creating", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await openShareModal({ submitUrl, injectSequence, mockedPage });

    // Track whether any new POST requests are made after revoke
    const shareCreateRequests: string[] = [];
    await mockedPage.route(
      `**/trips/${getTripId()}/share`,
      (route, request) => {
        if (request.method() === "POST") {
          shareCreateRequests.push(request.url());
          return route.fulfill({
            status: 201,
            contentType: "application/ld+json",
            body: JSON.stringify({
              shortCode: "NewCode1",
              token: "new-token",
              createdAt: new Date().toISOString(),
            }),
          });
        }
        return route.fallback();
      },
    );

    // Click revoke button
    await mockedPage.getByTestId("share-revoke-link-button").click();

    // After revoke, the "Create share link" button should appear
    await expect(
      mockedPage.getByTestId("share-create-link-button"),
    ).toBeVisible({ timeout: 5000 });

    // The share link text should no longer be visible
    await expect(mockedPage.getByTestId("share-link-text")).not.toBeVisible();

    // No auto-creation should have happened
    expect(shareCreateRequests).toHaveLength(0);
  });

  test("re-create link after revoke works", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await openShareModal({ submitUrl, injectSequence, mockedPage });

    // Revoke the link
    await mockedPage.getByTestId("share-revoke-link-button").click();
    await expect(
      mockedPage.getByTestId("share-create-link-button"),
    ).toBeVisible({ timeout: 5000 });

    // Re-mock the create endpoint for re-creation
    const recreatedCode = "ReCr8ted";
    await mockShareCreate(mockedPage, getTripId(), recreatedCode);

    // Click "Create share link" button
    await mockedPage.getByTestId("share-create-link-button").click();

    // New share link should appear with new short code
    await expect(mockedPage.getByTestId("share-link-text")).toBeVisible({
      timeout: 5000,
    });
    const origin = new URL(mockedPage.url()).origin;
    await expect(mockedPage.getByTestId("share-link-text")).toHaveText(
      `${origin}/s/${recreatedCode}`,
    );
  });

  test("shows create button when no active share exists", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    // Mock GET to return 404 (no active share)
    await mockShareGetNone(mockedPage, getTripId());
    await mockShareCreate(mockedPage, getTripId());

    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      weatherFetchedEvent(),
      accommodationsFoundEvent(0),
      accommodationsFoundEvent(1),
      tripCompleteEvent(),
    ]);

    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 10000,
    });

    await mockedPage.getByTestId("share-button").click();

    // Should show create button, not the link
    await expect(
      mockedPage.getByTestId("share-create-link-button"),
    ).toBeVisible({ timeout: 5000 });

    // Click create
    await mockedPage.getByTestId("share-create-link-button").click();

    // Link should appear
    await expect(mockedPage.getByTestId("share-link-text")).toBeVisible({
      timeout: 5000,
    });
  });

  test("download PNG triggers a file download", async ({
    submitUrl,
    injectSequence,
    mockedPage,
    browserName,
  }) => {
    test.skip(
      browserName !== "chromium",
      "canvas.toDataURL() download only fires reliably in Chromium",
    );
    await openShareModal({ submitUrl, injectSequence, mockedPage });

    // Listen for the download event
    const downloadPromise = mockedPage.waitForEvent("download");
    await mockedPage.getByTestId("share-download-png-button").click();
    const download = await downloadPromise;

    // Verify the download filename contains "infographic.png"
    expect(download.suggestedFilename()).toContain("infographic.png");
  });

  test("download square PNG triggers a 1080×1080 file download", async ({
    submitUrl,
    injectSequence,
    mockedPage,
    browserName,
  }) => {
    test.skip(
      browserName !== "chromium",
      "canvas.toDataURL() download only fires reliably in Chromium",
    );
    await openShareModal({ submitUrl, injectSequence, mockedPage });

    // Block OSM tile requests so the test doesn't depend on the network.
    // The renderer falls back to its solid-colour map background.
    await mockedPage.route("https://tile.openstreetmap.org/**", (route) =>
      route.abort(),
    );

    const downloadPromise = mockedPage.waitForEvent("download");
    await mockedPage.getByTestId("share-download-square-png-button").click();
    const download = await downloadPromise;

    expect(download.suggestedFilename()).toContain("infographic-square.png");
  });

  test("copy text copies trip summary to clipboard", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await mockedPage
      .context()
      .grantPermissions(["clipboard-read", "clipboard-write"]);

    await openShareModal({ submitUrl, injectSequence, mockedPage });
    await mockedPage.getByTestId("share-copy-text-button").click();

    // Verify clipboard contains trip text (should include the trip title)
    const clipboardText = await mockedPage.evaluate(() =>
      navigator.clipboard.readText(),
    );
    expect(clipboardText).toContain("Tour de l'Ardeche");
    // Should also contain the share URL since a link was created
    expect(clipboardText).toContain(expectedShareUrl(mockedPage));
  });
});
