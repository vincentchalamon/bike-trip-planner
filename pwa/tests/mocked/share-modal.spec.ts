import { test, expect } from "../fixtures/base.fixture";
import { getTripId } from "../fixtures/api-mocks";
import {
  routeParsedEvent,
  stagesComputedEvent,
  weatherFetchedEvent,
  accommodationsFoundEvent,
  tripCompleteEvent,
} from "../fixtures/mock-data";

const SHARE_ID = "share-uuid-abc-123";
const SHARE_TOKEN = "share-token-xyz";

/** Build the expected share URL using the page's actual origin (varies between local dev and CI). */
function expectedShareUrl(
  page: import("@playwright/test").Page,
  tripId: string,
  token: string,
): string {
  const origin = new URL(page.url()).origin;
  return `${origin}/shares/${tripId}?token=${token}`;
}

function mockShareCreate(
  page: import("@playwright/test").Page,
  tripId: string,
  token = SHARE_TOKEN,
  id = SHARE_ID,
) {
  return page.route(`**/trips/${tripId}/shares`, (route, request) => {
    if (request.method() !== "POST") return route.fallback();
    return route.fulfill({
      status: 201,
      contentType: "application/ld+json",
      body: JSON.stringify({
        id,
        token,
        expiresAt: null,
        createdAt: new Date().toISOString(),
      }),
    });
  });
}

function mockShareRevoke(
  page: import("@playwright/test").Page,
  tripId: string,
) {
  return page.route(
    `**/trips/${tripId}/shares/${SHARE_ID}`,
    (route, request) => {
      if (request.method() !== "DELETE") return route.fallback();
      return route.fulfill({ status: 204, body: "" });
    },
  );
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

  // Mock share API before opening modal (it auto-creates on open)
  await mockShareCreate(mockedPage, getTripId());
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

  // Open config panel then click share button
  await mockedPage
    .getByRole("button", { name: "Ouvrir les paramètres" })
    .click();
  await mockedPage.getByTestId("share-trip-button").click();

  // Wait for modal and share link to appear
  await expect(mockedPage.getByTestId("share-link-input")).toBeVisible({
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

    // Share link input should contain the client-built URL
    await expect(mockedPage.getByTestId("share-link-input")).toHaveValue(
      expectedShareUrl(mockedPage, getTripId(), SHARE_TOKEN),
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
    expect(clipboardText).toBe(
      expectedShareUrl(mockedPage, getTripId(), SHARE_TOKEN),
    );
  });

  test("revoke shows create share link button without auto-creating", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await openShareModal({ submitUrl, injectSequence, mockedPage });

    // Track whether any new POST /shares requests are made after revoke
    const shareCreateRequests: string[] = [];
    await mockedPage.route(
      `**/trips/${getTripId()}/shares`,
      (route, request) => {
        if (request.method() !== "POST") return route.fallback();
        shareCreateRequests.push(request.url());
        return route.fulfill({
          status: 201,
          contentType: "application/ld+json",
          body: JSON.stringify({
            id: "new-share-id",
            token: "new-token",
            expiresAt: null,
            createdAt: new Date().toISOString(),
          }),
        });
      },
    );

    // Click revoke button
    await mockedPage.getByTestId("share-revoke-link-button").click();

    // After revoke, the "Create share link" button should appear
    await expect(
      mockedPage.getByTestId("share-create-link-button"),
    ).toBeVisible({ timeout: 5000 });

    // The share link input should no longer be visible
    await expect(mockedPage.getByTestId("share-link-input")).not.toBeVisible();

    // No auto-creation should have happened (no POST request after revoke)
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
    const recreatedToken = "recreated-token";
    await mockedPage.route(
      `**/trips/${getTripId()}/shares`,
      (route, request) => {
        if (request.method() !== "POST") return route.fallback();
        return route.fulfill({
          status: 201,
          contentType: "application/ld+json",
          body: JSON.stringify({
            id: "recreated-share-id",
            token: recreatedToken,
            expiresAt: null,
            createdAt: new Date().toISOString(),
          }),
        });
      },
    );

    // Click "Create share link" button
    await mockedPage.getByTestId("share-create-link-button").click();

    // New share link should appear
    await expect(mockedPage.getByTestId("share-link-input")).toBeVisible({
      timeout: 5000,
    });
    await expect(mockedPage.getByTestId("share-link-input")).toHaveValue(
      expectedShareUrl(mockedPage, getTripId(), recreatedToken),
    );
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
    expect(clipboardText).toContain(
      expectedShareUrl(mockedPage, getTripId(), SHARE_TOKEN),
    );
  });
});
