import { test, expect } from "../fixtures/base.fixture";
import { getTripId } from "../fixtures/api-mocks";

/**
 * Issue #465 — Regression: without geolocation, planning actions still work.
 *
 * The in-ride wiring must not break the legacy planning flow. When the rider
 * has not granted geolocation, the chat request must NOT carry a `position`
 * payload and the planning actions (split_stage, etc.) must continue to
 * dispatch the matching shimmer skeleton.
 */

function chatUrlPattern(): RegExp {
  return new RegExp(
    `/trips/${getTripId().replace(/[-/\\^$*+?.()|[\]{}]/g, "\\$&")}/ai-chat$`,
  );
}

function chatHistoryUrlPattern(): RegExp {
  return new RegExp(
    `/trips/${getTripId().replace(/[-/\\^$*+?.()|[\]{}]/g, "\\$&")}/ai-chat-history`,
  );
}

test.describe("Chat planning regression", () => {
  test("split_stage action still dispatches shimmer when geoloc is unavailable", async ({
    createFullTrip,
    mockedPage,
  }) => {
    // Empty history so the loader resolves immediately.
    await mockedPage.route(chatHistoryUrlPattern(), (route) =>
      route.fulfill({
        status: 200,
        contentType: "application/ld+json",
        body: JSON.stringify({ member: [] }),
      }),
    );

    const captured: { body: unknown }[] = [];
    await mockedPage.route(chatUrlPattern(), async (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      try {
        captured.push({ body: JSON.parse(request.postData() ?? "{}") });
      } catch {
        captured.push({ body: null });
      }
      await route.fulfill({
        status: 200,
        contentType: "application/ld+json",
        body: JSON.stringify({
          tripId: getTripId(),
          action: "split_stage",
          params: { stage: 1 },
          response: "Je découpe l'étape 1.",
          dispatched: true,
          impactedStageNumbers: [1, 2],
          requiresFullAnalysis: false,
        }),
      });
    });

    await createFullTrip();
    await mockedPage.getByTestId("ai-bubble").click();

    // The geolocation prompt is visible because no position has been granted.
    await expect(
      mockedPage.getByTestId("ai-chat-panel-geoloc-prompt"),
    ).toBeVisible();

    await mockedPage
      .getByTestId("ai-chat-panel-input")
      .fill("Coupe l'étape 1 en deux");
    await mockedPage.getByTestId("ai-chat-panel-send").click();

    await expect(
      mockedPage
        .getByTestId("ai-chat-panel-message")
        .filter({ hasText: /je découpe l'étape 1/i }),
    ).toBeVisible({ timeout: 5_000 });

    // Shimmer skeletons fire on the impacted stages just like #311.
    await expect
      .poll(async () => mockedPage.getByTestId("stage-skeleton").count())
      .toBe(2);

    // The request body did NOT carry a position payload.
    expect(captured).toHaveLength(1);
    const body = captured[0]?.body as Record<string, unknown>;
    expect(body.message).toBe("Coupe l'étape 1 en deux");
    expect(body.position).toBeUndefined();
  });
});
