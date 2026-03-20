import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  weatherFetchedEvent,
  fullTripEventSequence,
  tripCompleteEvent,
} from "../fixtures/mock-data";

test.describe("Trip creation flow", () => {
  test("shows French placeholder in magic link input", async ({
    mockedPage,
  }) => {
    const input = mockedPage.getByTestId("magic-link-input");
    await expect(input).toHaveAttribute(
      "placeholder",
      "Collez votre lien Komoot ici...",
    );
  });

  test("shows validation error for invalid URL", async ({ mockedPage }) => {
    const input = mockedPage.getByTestId("magic-link-input");
    await input.fill("not-a-url");
    await input.press("Enter");
    await expect(
      mockedPage.getByText("Veuillez entrer une URL valide."),
    ).toBeVisible();
  });

  test("clears validation error when typing", async ({ mockedPage }) => {
    const input = mockedPage.getByTestId("magic-link-input");
    await input.fill("not-a-url");
    await input.press("Enter");
    await expect(
      mockedPage.getByText("Veuillez entrer une URL valide."),
    ).toBeVisible();
    await input.fill("https://");
    await expect(
      mockedPage.getByText("Veuillez entrer une URL valide."),
    ).toBeHidden();
  });

  test("creates trip on valid URL submit", async ({
    submitUrl,
    mockedPage,
  }) => {
    await submitUrl();
    await expect(
      mockedPage
        .getByTestId("trip-title-skeleton")
        .or(mockedPage.getByTestId("trip-title")),
    ).toBeVisible();
  });

  test("shows total distance after route_parsed", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await expect(mockedPage.getByTestId("total-distance")).toContainText(
      "187km",
    );
  });

  test("shows total elevation after route_parsed", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await expect(mockedPage.getByTestId("total-elevation")).toContainText(
      "2850m",
    );
  });

  test("shows 3 stage cards after stages_computed", async ({
    submitUrl,
    injectEvent,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectSequence([stagesComputedEvent()]);
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible();
    await expect(mockedPage.getByTestId("stage-card-2")).toBeVisible();
    await expect(mockedPage.getByTestId("stage-card-3")).toBeVisible();
  });

  test("shows difficulty badges on complete trip", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    // Stage 1: 72.5km, 1180m → Medium
    await expect(mockedPage.getByTestId("stage-card-1")).toContainText("Moyen");
    // Stage 2: 63.2km, 870m → Medium
    await expect(mockedPage.getByTestId("stage-card-2")).toContainText("Moyen");
    // Stage 3: 51.6km, 800m → Facile (< 60km and == 800 → medium boundary)
    // Actually 800 is not < 800, so it's medium
    await expect(mockedPage.getByTestId("stage-card-3")).toContainText("Moyen");
  });

  test("shows weather on stage cards", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      weatherFetchedEvent(),
    ]);
    await expect(mockedPage.getByTestId("stage-card-1")).toContainText(
      "Partly cloudy, 14-26°C",
    );
    await expect(mockedPage.getByTestId("stage-card-2")).toContainText(
      "Clear sky, 16-28°C",
    );
  });

  test("auto-submits on paste of valid URL", async ({ mockedPage }) => {
    const input = mockedPage.getByTestId("magic-link-input");
    await input.focus();
    // Dispatch a paste event with clipboardData
    await mockedPage.evaluate(() => {
      const el = document.querySelector(
        '[data-testid="magic-link-input"]',
      ) as HTMLInputElement;
      const event = new ClipboardEvent("paste", {
        bubbles: true,
        cancelable: true,
      });
      Object.defineProperty(event, "clipboardData", {
        value: {
          getData: () => "https://www.komoot.com/fr-fr/tour/12345",
        },
      });
      el.dispatchEvent(event);
    });
    // Should auto-submit — trip title skeleton or title should appear
    await expect(
      mockedPage
        .getByTestId("trip-title-skeleton")
        .or(mockedPage.getByTestId("trip-title")),
    ).toBeVisible({ timeout: 5000 });
  });

  test("auto-creates trip from ?link= query parameter", async ({
    mockedPage,
  }) => {
    // Navigate to URL with link query param
    await mockedPage.goto(
      "/?link=" +
        encodeURIComponent("https://www.komoot.com/fr-fr/tour/2795080048"),
    );
    // Trip creation should start automatically
    await expect(
      mockedPage
        .getByTestId("trip-title-skeleton")
        .or(mockedPage.getByTestId("trip-title")),
    ).toBeVisible({ timeout: 5000 });
    // The ?link= param should be consumed (URL cleaned)
    await expect(mockedPage).toHaveURL("/");
  });

  test("ignores invalid ?link= param without creating a trip", async ({
    mockedPage,
  }) => {
    await mockedPage.goto("/?link=not-a-valid-url");
    // Invalid URL is silently ignored — no trip created
    await expect(mockedPage).toHaveURL("/");
    await expect(
      mockedPage.getByTestId("magic-link-input"),
    ).toBeVisible();
    await expect(
      mockedPage
        .getByTestId("trip-title-skeleton")
        .or(mockedPage.getByTestId("trip-title")),
    ).not.toBeVisible();
  });

  test("shows stage locations after geocode resolves", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([routeParsedEvent(), stagesComputedEvent()]);
    // Wait for reverse geocode to resolve — labels come from mock
    await expect(mockedPage.getByTestId("stage-1-departure")).toContainText(
      "Aubenas",
      { timeout: 5000 },
    );
    await expect(mockedPage.getByTestId("stage-1-arrival")).toContainText(
      "Vals-les-Bains",
      { timeout: 5000 },
    );
  });
});
