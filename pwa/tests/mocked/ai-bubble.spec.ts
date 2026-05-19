import { test, expect } from "../fixtures/base.fixture";
import {
  fullTripEventSequence,
  stageUpdatedEvent,
} from "../fixtures/mock-data";
import { getTripId } from "../fixtures/api-mocks";

/**
 * Issue #310 — AiBubble floating assistant.
 *
 * Coverage:
 *  - The bubble opens/closes a 400×500 chat panel from the bottom right.
 *  - Submitting a message round-trips through `POST /trips/{id}/chat` and
 *    appends the assistant reply to the conversation.
 *  - Context-aware: the body sent to the backend carries
 *    `context.currentStage` matching the active day number.
 *  - Actionable replies (action != "info") trigger an
 *    `ai-chat-action` CustomEvent so the recomputation wiring (#311) can
 *    plug in without coupling to the panel.
 *  - The bubble is hidden during Acte 2 (`isAnalysisPhaseActive=true`).
 *  - On mobile viewports the panel covers the full screen.
 */

function chatUrlPattern(): RegExp {
  return new RegExp(
    `/trips/${getTripId().replace(/[-/\\^$*+?.()|[\]{}]/g, "\\$&")}/chat$`,
  );
}

async function mockChat(
  page: import("@playwright/test").Page,
  responseBody: {
    action: string;
    params: Record<string, unknown>;
    response: string;
    dispatched?: boolean;
    impactedStageNumbers?: number[];
    requiresFullAnalysis?: boolean;
  },
  capturedRequests: { body: unknown }[],
): Promise<void> {
  await page.route(chatUrlPattern(), async (route, request) => {
    if (request.method() !== "POST") return route.fallback();
    try {
      capturedRequests.push({ body: JSON.parse(request.postData() ?? "{}") });
    } catch {
      capturedRequests.push({ body: null });
    }
    await route.fulfill({
      status: 200,
      contentType: "application/ld+json",
      body: JSON.stringify({
        tripId: getTripId(),
        action: responseBody.action,
        params: responseBody.params,
        response: responseBody.response,
        dispatched: responseBody.dispatched ?? false,
        impactedStageNumbers: responseBody.impactedStageNumbers ?? [],
        requiresFullAnalysis: responseBody.requiresFullAnalysis ?? false,
      }),
    });
  });
}

test.describe("AiBubble — open/close", () => {
  test("opens the chat panel when clicked and closes it via the close button", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();

    const bubble = mockedPage.getByTestId("ai-bubble");
    await expect(bubble).toBeVisible({ timeout: 10_000 });

    await expect(mockedPage.getByTestId("ai-chat-panel")).toHaveCount(0);

    await bubble.click();
    await expect(mockedPage.getByTestId("ai-chat-panel")).toBeVisible();

    // The "Nouveau" badge disappears on first open and stays gone across toggles.
    await expect(mockedPage.getByTestId("ai-bubble-badge")).toHaveCount(0);

    await mockedPage.getByTestId("ai-chat-panel-close").click();
    await expect(mockedPage.getByTestId("ai-chat-panel")).toHaveCount(0);
  });
});

test.describe("AiBubble — chat round-trip", () => {
  test("sends a message, appends the assistant reply, and propagates currentStage in the context", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();

    const captured: { body: unknown }[] = [];
    await mockChat(
      mockedPage,
      {
        action: "info",
        params: {},
        response: "Compris, voici ce que je peux vous dire…",
      },
      captured,
    );

    // Select stage 3 so `activeDayNumber` propagates into `currentContext`.
    await mockedPage.evaluate(() => {
      window.dispatchEvent(
        new CustomEvent("__test_set_active_day_number", { detail: 3 }),
      );
    });

    await mockedPage.getByTestId("ai-bubble").click();
    await expect(mockedPage.getByTestId("ai-chat-panel-hint")).toContainText(
      /étape 3/i,
    );

    const input = mockedPage.getByTestId("ai-chat-panel-input");
    await input.fill("Que penses-tu de cette étape ?");
    await mockedPage.getByTestId("ai-chat-panel-send").click();

    // The assistant bubble appears in the history.
    await expect(
      mockedPage
        .getByTestId("ai-chat-panel-message")
        .filter({ hasText: /Compris, voici ce que je peux vous dire/i }),
    ).toBeVisible({ timeout: 5_000 });

    expect(captured).toHaveLength(1);
    const body = captured[0]?.body as {
      message?: string;
      context?: { currentStage?: number | null };
    };
    expect(body.message).toBe("Que penses-tu de cette étape ?");
    expect(body.context?.currentStage).toBe(3);
  });

  test("dispatches an ai-chat-action event for actionable replies", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();

    await mockChat(
      mockedPage,
      {
        action: "split_stage",
        params: { stage: 3 },
        response: "Bien sûr, je divise l'étape 3.",
        dispatched: true,
      },
      [],
    );

    // Subscribe before triggering the round-trip so we capture the event.
    await mockedPage.evaluate(() => {
      const w = window as Window & { __aiChatActionDetail?: unknown };
      document.addEventListener(
        "ai-chat-action",
        (event) => {
          w.__aiChatActionDetail = (event as CustomEvent<unknown>).detail;
        },
        { once: true },
      );
    });

    await mockedPage.getByTestId("ai-bubble").click();
    await mockedPage
      .getByTestId("ai-chat-panel-input")
      .fill("Divise l'étape 3");
    await mockedPage.getByTestId("ai-chat-panel-send").click();

    await expect(
      mockedPage
        .getByTestId("ai-chat-panel-message")
        .filter({ hasText: /je divise l'étape 3/i }),
    ).toBeVisible({ timeout: 5_000 });

    const detail = await mockedPage.evaluate(
      () =>
        (window as Window & { __aiChatActionDetail?: unknown })
          .__aiChatActionDetail,
    );
    expect(detail).toMatchObject({
      action: "split_stage",
      params: { stage: 3 },
      dispatched: true,
    });
  });
});

test.describe("AiBubble — visibility gating", () => {
  test("is hidden while Acte 2 (analysis phase) is active", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();

    await expect(mockedPage.getByTestId("ai-bubble")).toBeVisible();

    await mockedPage.evaluate(() => {
      window.dispatchEvent(
        new CustomEvent("__test_set_analysis_started", { detail: true }),
      );
    });

    await expect(mockedPage.getByTestId("ai-bubble")).toHaveCount(0);
  });
});

test.describe("AiBubble — inline recomputation (#311)", () => {
  test("split_stage action triggers shimmer on the two impacted stages and clears it when stage_updated lands", async ({
    createFullTrip,
    mockedPage,
    injectSequence,
  }) => {
    await createFullTrip();

    await mockChat(
      mockedPage,
      {
        action: "split_stage",
        params: { stage: 1 },
        response: "Je découpe l'étape 1 en deux.",
        dispatched: true,
        impactedStageNumbers: [1, 2],
      },
      [],
    );

    await mockedPage.getByTestId("ai-bubble").click();
    await mockedPage
      .getByTestId("ai-chat-panel-input")
      .fill("Coupe l'étape 1 en deux");
    await mockedPage.getByTestId("ai-chat-panel-send").click();

    // Wait for the assistant reply to confirm the response landed.
    await expect(
      mockedPage
        .getByTestId("ai-chat-panel-message")
        .filter({ hasText: /je découpe l'étape 1/i }),
    ).toBeVisible({ timeout: 5_000 });

    // The two impacted stage cards now render as shimmer skeletons.
    await expect
      .poll(async () => mockedPage.getByTestId("stage-skeleton").count())
      .toBe(2);

    // Inject a stage_updated event for stage 0 — one shimmer clears.
    await injectSequence([stageUpdatedEvent(0)]);

    await expect
      .poll(async () => mockedPage.getByTestId("stage-skeleton").count())
      .toBe(1);
  });

  test("info action does not mark any stage as recomputing", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();

    await mockChat(
      mockedPage,
      {
        action: "info",
        params: {},
        response: "Le gravel désigne…",
      },
      [],
    );

    await mockedPage.getByTestId("ai-bubble").click();
    await mockedPage
      .getByTestId("ai-chat-panel-input")
      .fill("C'est quoi le gravel ?");
    await mockedPage.getByTestId("ai-chat-panel-send").click();

    await expect(
      mockedPage
        .getByTestId("ai-chat-panel-message")
        .filter({ hasText: /Le gravel désigne/i }),
    ).toBeVisible({ timeout: 5_000 });

    await expect(mockedPage.getByTestId("stage-skeleton")).toHaveCount(0);
  });

  test("change_route action surfaces the relaunch full-analysis button", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();

    await mockChat(
      mockedPage,
      {
        action: "change_route",
        params: {},
        response: "Cette modification touche tout le tracé.",
        requiresFullAnalysis: true,
      },
      [],
    );

    await mockedPage.getByTestId("ai-bubble").click();
    await mockedPage
      .getByTestId("ai-chat-panel-input")
      .fill("Change l'itinéraire pour passer par la côte");
    await mockedPage.getByTestId("ai-chat-panel-send").click();

    await expect(
      mockedPage.getByTestId("ai-chat-panel-full-analysis"),
    ).toBeVisible({ timeout: 5_000 });
    await expect(
      mockedPage.getByTestId("ai-chat-panel-relaunch"),
    ).toBeVisible();
  });
});

test.describe("AiBubble — responsive", () => {
  test("renders the panel as a full-screen sheet on mobile", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await mockedPage.setViewportSize({ width: 375, height: 800 });

    await createFullTrip();

    await mockedPage.getByTestId("ai-bubble").click();
    const panel = mockedPage.getByTestId("ai-chat-panel");
    await expect(panel).toBeVisible();

    const box = await panel.boundingBox();
    expect(box?.width).toBeGreaterThanOrEqual(350);
    expect(box?.height).toBeGreaterThanOrEqual(700);
  });
});

test.describe("AiBubble — error handling", () => {
  test("surfaces the rate-limit message on HTTP 429", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    await mockedPage.route(chatUrlPattern(), (route) =>
      route.fulfill({
        status: 429,
        contentType: "application/problem+json",
        body: "{}",
      }),
    );

    await mockedPage.getByTestId("ai-bubble").click();
    await mockedPage.getByTestId("ai-chat-panel-input").fill("test");
    await mockedPage.getByTestId("ai-chat-panel-send").click();

    await expect(
      mockedPage.getByTestId("ai-chat-panel-message").last(),
    ).toContainText(/limite de messages/i, { timeout: 5_000 });
  });

  test("surfaces the unavailable message on HTTP 503", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    await mockedPage.route(chatUrlPattern(), (route) =>
      route.fulfill({
        status: 503,
        contentType: "application/problem+json",
        body: "{}",
      }),
    );

    await mockedPage.getByTestId("ai-bubble").click();
    await mockedPage.getByTestId("ai-chat-panel-input").fill("test");
    await mockedPage.getByTestId("ai-chat-panel-send").click();

    await expect(
      mockedPage.getByTestId("ai-chat-panel-message").last(),
    ).toContainText(/temporairement indisponible/i, { timeout: 5_000 });
  });

  test("surfaces the network message on connection failure", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    await mockedPage.route(chatUrlPattern(), (route) => route.abort("failed"));

    await mockedPage.getByTestId("ai-bubble").click();
    await mockedPage.getByTestId("ai-chat-panel-input").fill("test");
    await mockedPage.getByTestId("ai-chat-panel-send").click();

    await expect(
      mockedPage.getByTestId("ai-chat-panel-message").last(),
    ).toContainText(/erreur réseau/i, { timeout: 5_000 });
  });
});

test.describe("AiBubble — cross-trip isolation", () => {
  test("clears chat history when the active trip changes", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();

    const captured: { body: unknown }[] = [];
    await mockChat(
      mockedPage,
      { action: "info", params: {}, response: "Reply from trip A." },
      captured,
    );

    // Send one message so chatHistory is non-empty (the panel also renders a
    // static greeting bubble, so the transcript holds 3 messages here).
    await mockedPage.getByTestId("ai-bubble").click();
    await mockedPage.getByTestId("ai-chat-panel-input").fill("Hello trip A");
    await mockedPage.getByTestId("ai-chat-panel-send").click();
    await expect(
      mockedPage
        .getByTestId("ai-chat-panel-message")
        .filter({ hasText: /Reply from trip A/i }),
    ).toBeVisible({ timeout: 5_000 });
    await expect(mockedPage.getByTestId("ai-chat-panel-message")).toHaveCount(
      3,
    );
    await mockedPage.getByTestId("ai-chat-panel-close").click();

    // Switch the active trip identifier in the store — the trip-planner
    // useEffect keyed on tripId should fire and call clearHistory().
    await mockedPage.evaluate(() => {
      window.dispatchEvent(
        new CustomEvent("__test_set_trip_id", {
          detail: "01999999-0000-7000-8000-000000000099",
        }),
      );
    });

    // Reopening the bubble must only render the static greeting, not the
    // user/assistant turns from the previous trip.
    await mockedPage.getByTestId("ai-bubble").click();
    await expect(mockedPage.getByTestId("ai-chat-panel-message")).toHaveCount(
      1,
    );
  });

  test("discards a stale network error after a trip switch", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();

    // Hold the chat request so we can switch trips before it settles.
    let releaseRoute!: () => void;
    await mockedPage.route(chatUrlPattern(), async (route) => {
      await new Promise<void>((res) => {
        releaseRoute = res;
      });
      await route.abort("failed");
    });

    await mockedPage.getByTestId("ai-bubble").click();
    await mockedPage.getByTestId("ai-chat-panel-input").fill("Hello trip A");
    await mockedPage.getByTestId("ai-chat-panel-send").click();
    await mockedPage.getByTestId("ai-chat-panel-close").click();

    // Switch trips while the request is still in-flight.
    await mockedPage.evaluate(() => {
      window.dispatchEvent(
        new CustomEvent("__test_set_trip_id", {
          detail: "01999999-0000-7000-8000-000000000099",
        }),
      );
    });

    // Re-open the bubble on the new trip, then let the stale request fail.
    await mockedPage.getByTestId("ai-bubble").click();
    releaseRoute();

    // Only the static greeting should remain — no error bubble from trip A.
    await expect(mockedPage.getByTestId("ai-chat-panel-message")).toHaveCount(
      1,
      { timeout: 5_000 },
    );
  });
});
