import { test, expect } from "../fixtures/base.fixture";
import { routeParsedEvent, stagesComputedEvent } from "../fixtures/mock-data";

async function openConfigPanel(
  mockedPage: import("@playwright/test").Page,
): Promise<void> {
  await mockedPage
    .getByRole("button", { name: "Ouvrir les paramètres" })
    .click();
  await expect(
    mockedPage.getByRole("dialog", { name: "Paramètres" }),
  ).toBeInViewport();
}

test.describe("Travel time estimation", () => {
  test("displays departure and arrival times on stage card", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(stagesComputedEvent());

    // Stage 1: 72.5 km, 1180m D+, default speed 15 km/h, departure 8h
    // Effective speed = 15 - 2*(1180/500) = 15 - 4.72 = 10.28 km/h
    // Duration = 72.5 / 10.28 ≈ 7.05 h → arrival ≈ 15h03 → "15h03"
    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard).toBeVisible();

    // Travel time indicator should be visible (contains a clock icon and time info)
    const travelTimeText = stageCard.getByTitle(
      "Estimation basée sur la vitesse moyenne et le dénivelé (règle de Naismith adaptée au vélo)",
    );
    await expect(travelTimeText).toBeVisible();
  });

  test("departure time shows 8h00 by default", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(stagesComputedEvent());

    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard).toBeVisible();

    // Default departure is 8h → "Départ ~8h00"
    await expect(stageCard).toContainText("Départ ~8h00");
  });

  test("departure hour slider is visible in config panel", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await openConfigPanel(mockedPage);

    const departureHourSlider = mockedPage.getByRole("slider", {
      name: "Heure de départ (0-23)",
    });
    await expect(departureHourSlider).toBeVisible();
    await expect(departureHourSlider).toHaveValue("8");
  });

  test("changing departure hour updates stage card display", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent(stagesComputedEvent());
    await openConfigPanel(mockedPage);

    const departureHourSlider = mockedPage.getByRole("slider", {
      name: "Heure de départ (0-23)",
    });
    await departureHourSlider.fill("6");

    // Close config panel
    await mockedPage
      .getByRole("button", { name: "Fermer les paramètres" })
      .click();

    // Stage card should now show departure at 6h00
    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard).toContainText("Départ ~6h00");
  });

  test("rest day card does not show travel time", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    await injectEvent({
      type: "stages_computed",
      data: {
        stages: [
          {
            dayNumber: 1,
            distance: 72.5,
            elevation: 1180,
            elevationLoss: 920,
            startPoint: { lat: 44.735, lon: 4.598, ele: 280 },
            endPoint: { lat: 44.532, lon: 4.392, ele: 540 },
            geometry: [],
            label: null,
          },
          {
            dayNumber: 2,
            distance: 0,
            elevation: 0,
            elevationLoss: 0,
            startPoint: { lat: 44.532, lon: 4.392, ele: 540 },
            endPoint: { lat: 44.532, lon: 4.392, ele: 540 },
            geometry: [],
            label: null,
            isRestDay: true,
          },
          {
            dayNumber: 3,
            distance: 63.2,
            elevation: 870,
            elevationLoss: 1050,
            startPoint: { lat: 44.532, lon: 4.392, ele: 540 },
            endPoint: { lat: 44.295, lon: 4.087, ele: 360 },
            geometry: [],
            label: null,
          },
        ],
      },
    });

    // Rest day card should not have the travel time tooltip
    const restDayCard = mockedPage.getByTestId("rest-day-card-1");
    await expect(restDayCard).toBeVisible();
    await expect(restDayCard).not.toContainText("Départ ~");
  });
});
