import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  weatherFetchedEvent,
  terrainAlertsEvent,
  tripCompleteEvent,
} from "../fixtures/mock-data";

test.describe("Alerts and weather", () => {
  test("shows terrain alerts on correct stages", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      terrainAlertsEvent(),
      tripCompleteEvent(),
    ]);
    // Stage 1 (index 0) has a warning
    await expect(mockedPage.getByTestId("stage-card-1")).toContainText(
      "Route non goudronnee sur 3km",
    );
    // Stage 2 (index 1) has a nudge
    await expect(mockedPage.getByTestId("stage-card-2")).toContainText(
      "Passage en altitude (820m)",
    );
  });

  test("shows weather on each stage card", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      weatherFetchedEvent(),
      tripCompleteEvent(),
    ]);
    await expect(mockedPage.getByTestId("stage-card-1")).toContainText(
      "Partly cloudy, 14-26°C",
    );
    await expect(mockedPage.getByTestId("stage-card-2")).toContainText(
      "Clear sky, 16-28°C",
    );
    await expect(mockedPage.getByTestId("stage-card-3")).toContainText(
      "Overcast, 12-22°C",
    );

    // Comfort index badge is visible with correct value
    const comfortBadge = mockedPage
      .getByTitle(/Comfort: \d+\/100/)
      .or(mockedPage.getByTitle(/Confort\s*: \d+\/100/))
      .first();
    await expect(comfortBadge).toBeVisible();

    // Humidity is displayed
    await expect(
      mockedPage.getByTitle(/Humidity|Humidité/).first(),
    ).toBeVisible();

    // Stage 3 has headwind — relative wind label is shown
    await expect(mockedPage.getByTestId("stage-card-3")).toContainText(
      /15 km\/h.*(Headwind|Vent de face)/,
    );
  });

  test("shows weather in summary bar", async ({
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
    // Summary bar shows first stage weather
    await expect(
      mockedPage.getByText("Partly cloudy, 14-26°C").first(),
    ).toBeVisible();
  });

  test("shows loading text before alerts arrive", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([routeParsedEvent(), stagesComputedEvent()]);
    // While processing and no alerts, should show loading
    await expect(
      mockedPage.getByText("Analyse de l'étape...").first(),
    ).toBeVisible();
  });

  test("disabling ebike mode clears terrain alerts immediately", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      terrainAlertsEvent(),
      tripCompleteEvent(),
    ]);
    await expect(mockedPage.getByTestId("stage-card-1")).toContainText(
      "Route non goudronnee sur 3km",
    );

    // VAE toggle is inside the ConfigPanel — open it first
    await mockedPage
      .getByRole("button", { name: "Ouvrir les paramètres" })
      .click();
    await expect(
      mockedPage.getByRole("dialog", { name: "Paramètres" }),
    ).toBeInViewport();

    const ebikeToggle = mockedPage.getByRole("switch", { name: "Mode VAE" });
    await ebikeToggle.click(); // enable
    await ebikeToggle.click(); // disable — optimistic clear, no SSE fired

    await expect(mockedPage.getByTestId("stage-card-1")).not.toContainText(
      "Route non goudronnee sur 3km",
    );
  });
});
