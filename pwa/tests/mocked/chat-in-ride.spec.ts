import { test, expect } from "../fixtures/base.fixture";
import { getTripId } from "../fixtures/api-mocks";

/**
 * Issue #465 — In-ride chat: PoiCard + InRideDisclaimer + geolocation wiring.
 *
 * Coverage:
 *  - When the rider has granted geolocation, the chat request carries the
 *    `position` payload alongside the message.
 *  - The assistant response containing `pois` renders one PoiCard per
 *    suggestion with the backend-provided deeplink and the safety disclaimer.
 *  - The "+km" detour badge and the warning variant ("no opening hours")
 *    surface on the affected card.
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

async function stubGeolocation(
  page: import("@playwright/test").Page,
  lat: number,
  lon: number,
): Promise<void> {
  // Playwright's BrowserContext exposes a first-class geolocation API; using
  // it (rather than overriding navigator.geolocation via addInitScript) is the
  // only way that survives Chromium's non-configurable property descriptor on
  // navigator.geolocation in headless mode.
  const context = page.context();
  await context.grantPermissions(["geolocation"]);
  await context.setGeolocation({ latitude: lat, longitude: lon });
}

test.describe("Chat in-ride — POI suggestions", () => {
  test("forwards the GPS position and renders POI cards with deeplink + disclaimer", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await stubGeolocation(mockedPage, 48.8566, 2.3522);

    // Empty history so the panel skips straight into the conversation.
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
          action: "find_poi",
          params: { category: "food" },
          response: "Voici trois boulangeries proches.",
          dispatched: false,
          impactedStageNumbers: [],
          requiresFullAnalysis: false,
          pois: [
            {
              name: "Boulangerie du Marché",
              category: "food",
              lat: 48.857,
              lon: 2.353,
              distance_m: 450,
              detour_m: 200,
              opening_hours_today: "Mo-Sa 07:00-19:30",
              closes_at: "2026-05-19T19:30:00+02:00",
              phone: "+33123456789",
              deeplink: "https://maps.google.com/?q=48.857,2.353",
              warning: null,
            },
            {
              name: "Café de la Gare",
              category: "food",
              lat: 48.86,
              lon: 2.356,
              distance_m: 1200,
              detour_m: 0,
              opening_hours_today: null,
              closes_at: null,
              phone: null,
              deeplink: "https://maps.google.com/?q=48.86,2.356",
              warning: null,
            },
          ],
        }),
      });
    });

    await createFullTrip();
    await mockedPage.getByTestId("ai-bubble").click();

    // Trigger the geolocation request from the prompt button so the hook
    // captures a fix before we send the chat message.
    await mockedPage.getByTestId("ai-chat-panel-geoloc-prompt").click();

    // Give the stubbed getCurrentPosition a tick to resolve.
    await expect(async () => {
      // The geolocation prompt disappears once a position is captured.
      await expect(
        mockedPage.getByTestId("ai-chat-panel-geoloc-prompt"),
      ).toHaveCount(0);
    }).toPass({ timeout: 3_000 });

    const input = mockedPage.getByTestId("ai-chat-panel-input");
    await input.fill("Une boulangerie pas trop loin ?");
    await mockedPage.getByTestId("ai-chat-panel-send").click();

    await expect(
      mockedPage
        .getByTestId("ai-chat-panel-message")
        .filter({ hasText: /trois boulangeries/i }),
    ).toBeVisible({ timeout: 5_000 });

    // POI cards are rendered with one per suggestion.
    const cards = mockedPage.getByTestId("poi-card");
    await expect(cards).toHaveCount(2);

    // First card: deeplink open-in-maps button with target=_blank and rel.
    const firstMapsLink = mockedPage.getByTestId("poi-card-open-maps").first();
    await expect(firstMapsLink).toHaveAttribute(
      "href",
      "https://maps.google.com/?q=48.857,2.353",
    );
    await expect(firstMapsLink).toHaveAttribute("target", "_blank");
    await expect(firstMapsLink).toHaveAttribute("rel", /noopener noreferrer/);

    // Detour badge appears only on the first card.
    await expect(mockedPage.getByTestId("poi-card-detour")).toHaveCount(1);

    // The second card has no opening hours → the warning variant is rendered.
    await expect(mockedPage.getByTestId("poi-card-no-hours")).toHaveCount(1);

    // Disclaimer is rendered beneath the cards.
    await expect(mockedPage.getByTestId("in-ride-disclaimer")).toBeVisible();

    // The chat request carried the position payload.
    expect(captured).toHaveLength(1);
    const body = captured[0]?.body as {
      message?: string;
      position?: { lat?: number; lon?: number };
    };
    expect(body.message).toBe("Une boulangerie pas trop loin ?");
    expect(body.position?.lat).toBeCloseTo(48.8566, 3);
    expect(body.position?.lon).toBeCloseTo(2.3522, 3);
  });
});

test.describe("Chat — provider error mapping (#761)", () => {
  test("shows the settings CTA banner instead of a retry bubble on a 422 invalid token", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await mockedPage.route(chatHistoryUrlPattern(), (route) =>
      route.fulfill({
        status: 200,
        contentType: "application/ld+json",
        body: JSON.stringify({ member: [] }),
      }),
    );

    // No geolocation → planning mode, the branch that maps a rejected key to a
    // 422 with a discrete error code (the in-ride POI branch degrades instead).
    await mockedPage.route(chatUrlPattern(), async (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      await route.fulfill({
        status: 422,
        contentType: "application/json",
        body: JSON.stringify({ error: "ai_invalid_token" }),
      });
    });

    await createFullTrip();
    await mockedPage.getByTestId("ai-bubble").click();

    await mockedPage
      .getByTestId("ai-chat-panel-input")
      .fill("Quel est mon dénivelé total ?");
    await mockedPage.getByTestId("ai-chat-panel-send").click();

    // The actionable banner + settings CTA replace the generic "retry" bubble.
    await expect(
      mockedPage.getByTestId("ai-chat-panel-config-error"),
    ).toBeVisible({ timeout: 5_000 });
    await expect(
      mockedPage.getByTestId("ai-chat-panel-configure-cta"),
    ).toHaveAttribute("href", "/account/settings#ai");
  });
});
