import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  tripCompleteEvent,
  stageUpdatedEvent,
  accommodationsFoundEvent,
} from "../fixtures/mock-data";

/**
 * Issue #327 — Batch mode: ModificationQueue (accumulation + single recompute).
 *
 * The user can accumulate several modifications before applying them in a single
 * `POST /trips/{id}/recompute` call. The ModificationQueue floating panel shows:
 * - The list of pending modifications
 * - An estimated recompute time
 * - "Apply all" and "Cancel" actions
 *
 * "Apply all" → sends one request → shows shimmer skeleton(s)
 * "Cancel"    → clears the queue, restores previous state
 */

/**
 * Helper: inject a `__test_queue_modification` custom event so the test can
 * populate the pending modifications queue without wiring through real UI
 * actions. The `trip-planner.tsx` E2E test hook (similar pattern to
 * `__test_set_focused_map_stage`) dispatches this to the store directly.
 */
async function queueModification(
  page: import("@playwright/test").Page,
  modification: {
    stageIndex: number | null;
    type: "accommodation" | "distance" | "dates" | "pacing";
    label: string;
  },
) {
  await page.evaluate((mod) => {
    window.dispatchEvent(
      new CustomEvent("__test_queue_modification", { detail: mod }),
    );
  }, modification);
}

test.describe("ModificationQueue — display", () => {
  test("modification queue panel is hidden when no modifications are pending", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    await expect(mockedPage.getByTestId("modification-queue")).toBeHidden();
  });

  test("modification queue panel appears after queueing a modification", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();

    await queueModification(mockedPage, {
      stageIndex: 0,
      type: "accommodation",
      label: "Hébergement étape 1 : Camping Les Oliviers",
    });

    await expect(mockedPage.getByTestId("modification-queue")).toBeVisible({
      timeout: 3000,
    });
  });

  test("counter reflects the number of pending modifications", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();

    await queueModification(mockedPage, {
      stageIndex: 0,
      type: "accommodation",
      label: "Hébergement étape 1 : Camping Les Oliviers",
    });
    await queueModification(mockedPage, {
      stageIndex: 1,
      type: "distance",
      label: "Distance étape 2 : 55 km → 65 km",
    });
    await queueModification(mockedPage, {
      stageIndex: null,
      type: "dates",
      label: "Dates : 15 juin → 18 juin",
    });

    const countEl = mockedPage.getByTestId("modification-queue-count");
    await expect(countEl).toBeVisible({ timeout: 3000 });
    await expect(countEl).toContainText("3");
  });

  test("modification labels are listed in the queue panel", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();

    await queueModification(mockedPage, {
      stageIndex: 0,
      type: "accommodation",
      label: "Hébergement étape 1 : Camping Les Oliviers",
    });
    await queueModification(mockedPage, {
      stageIndex: 1,
      type: "distance",
      label: "Distance étape 2 : 55 km → 65 km",
    });

    const list = mockedPage.getByTestId("modification-queue-list");
    await expect(list).toBeVisible({ timeout: 3000 });
    await expect(list).toContainText("Hébergement étape 1");
    await expect(list).toContainText("Distance étape 2");
  });

  test("estimated recompute time is displayed", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();

    await queueModification(mockedPage, {
      stageIndex: 0,
      type: "accommodation",
      label: "Hébergement étape 1",
    });

    const estimate = mockedPage.getByTestId("modification-queue-estimate");
    await expect(estimate).toBeVisible({ timeout: 3000 });
    // Should show some time estimate (contains "~" and either "s" or "min")
    await expect(estimate).toContainText("~");
  });

  test("duplicate modification type+stageIndex replaces existing entry", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();

    await queueModification(mockedPage, {
      stageIndex: 0,
      type: "accommodation",
      label: "Hébergement étape 1 : Gîte du Moulin",
    });
    // Queue the same type + stageIndex again — should replace, not append
    await queueModification(mockedPage, {
      stageIndex: 0,
      type: "accommodation",
      label: "Hébergement étape 1 : Camping Les Oliviers",
    });

    const countEl = mockedPage.getByTestId("modification-queue-count");
    // Still only 1 entry (replacement, not addition)
    await expect(countEl).toContainText("1");
    await expect(
      mockedPage.getByTestId("modification-queue-list"),
    ).toContainText("Camping Les Oliviers");
    await expect(
      mockedPage.getByTestId("modification-queue-list"),
    ).not.toContainText("Gîte du Moulin");
  });
});

test.describe("ModificationQueue — apply all", () => {
  test("'Apply all' sends a recompute request and shows shimmer skeleton", async ({
    createFullTrip,
    mockedPage,
  }) => {
    // Intercept the recompute request so we can control the response timing
    let recomputeResolve: (() => void) | undefined;
    await mockedPage.route("**/trips/*/recompute", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      // Hold the response until the test resolves it
      return new Promise<void>((resolve) => {
        recomputeResolve = () => {
          resolve();
          void route.fulfill({
            status: 202,
            contentType: "application/ld+json",
            body: JSON.stringify({ "@type": "Trip", id: "test-trip-abc-123" }),
          });
        };
      });
    });

    await createFullTrip();

    await queueModification(mockedPage, {
      stageIndex: 0,
      type: "accommodation",
      label: "Hébergement étape 1",
    });

    await expect(mockedPage.getByTestId("modification-queue")).toBeVisible({
      timeout: 3000,
    });

    await mockedPage.getByTestId("modification-queue-apply").click();

    // The "Applying…" state should be visible while the request is in-flight
    await expect(mockedPage.getByTestId("modification-queue-apply")).toContainText(/appli/i, {
      timeout: 3000,
    });

    // Resolve the request
    recomputeResolve?.();

    // After success the queue panel should disappear
    await expect(mockedPage.getByTestId("modification-queue")).toBeHidden({
      timeout: 5000,
    });
  });

  test("'Apply all' sends a single request for multiple modifications", async ({
    createFullTrip,
    mockedPage,
  }) => {
    const recomputeRequests: unknown[] = [];
    await mockedPage.route("**/trips/*/recompute", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      recomputeRequests.push(JSON.parse(request.postData() ?? "{}") as unknown);
      return route.fulfill({
        status: 202,
        contentType: "application/ld+json",
        body: JSON.stringify({ "@type": "Trip", id: "test-trip-abc-123" }),
      });
    });

    await createFullTrip();

    await queueModification(mockedPage, {
      stageIndex: 0,
      type: "accommodation",
      label: "Hébergement étape 1",
    });
    await queueModification(mockedPage, {
      stageIndex: 1,
      type: "distance",
      label: "Distance étape 2",
    });
    await queueModification(mockedPage, {
      stageIndex: null,
      type: "dates",
      label: "Dates du voyage",
    });

    await mockedPage.getByTestId("modification-queue-apply").click();

    // Wait for the request to be captured
    await mockedPage.waitForTimeout(500);

    // Exactly one recompute request was sent (batch mode)
    expect(recomputeRequests).toHaveLength(1);

    // The body should contain all 3 modifications
    const body = recomputeRequests[0] as {
      modifications: Array<{ type: string }>;
    };
    expect(body.modifications).toHaveLength(3);
  });

  test("shimmer skeleton appears for affected stage after apply", async ({
    createFullTrip,
    injectEvent,
    mockedPage,
  }) => {
    await mockedPage.route("**/trips/*/recompute", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({
        status: 202,
        contentType: "application/ld+json",
        body: JSON.stringify({ "@type": "Trip", id: "test-trip-abc-123" }),
      });
    });

    await createFullTrip();

    await queueModification(mockedPage, {
      stageIndex: 0,
      type: "accommodation",
      label: "Hébergement étape 1",
    });

    await mockedPage.getByTestId("modification-queue-apply").click();

    // Shimmer should appear for the affected stage
    await expect(
      mockedPage.getByTestId("stage-skeleton").first(),
    ).toBeVisible({ timeout: 5000 });

    // Original stage card should be hidden
    await expect(mockedPage.getByTestId("stage-card-1")).toBeHidden();

    // Inject stage_updated to resolve the recomputation
    await injectEvent(stageUpdatedEvent(0));
    await injectEvent(stageUpdatedEvent(1));

    // Stage card should be restored
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 3000,
    });
  });
});

test.describe("ModificationQueue — cancel", () => {
  test("'Cancel' clears the queue and hides the panel", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();

    await queueModification(mockedPage, {
      stageIndex: 0,
      type: "accommodation",
      label: "Hébergement étape 1",
    });

    await expect(mockedPage.getByTestId("modification-queue")).toBeVisible({
      timeout: 3000,
    });

    await mockedPage.getByTestId("modification-queue-cancel").click();

    // Panel should be gone after cancellation
    await expect(mockedPage.getByTestId("modification-queue")).toBeHidden({
      timeout: 3000,
    });
  });

  test("'Cancel' restores original stage cards (no shimmer)", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();

    await queueModification(mockedPage, {
      stageIndex: 0,
      type: "distance",
      label: "Distance étape 1",
    });

    await mockedPage.getByTestId("modification-queue-cancel").click();

    // All stage cards should still be visible (no recomputation triggered)
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 3000,
    });
    await expect(mockedPage.getByTestId("stage-card-2")).toBeVisible();
    await expect(mockedPage.getByTestId("stage-card-3")).toBeVisible();

    // No shimmer should appear
    await expect(mockedPage.getByTestId("stage-skeleton")).toBeHidden();
  });
});
