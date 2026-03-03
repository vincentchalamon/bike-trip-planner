import { test, expect } from "../fixtures/base.fixture";
import { routeParsedEvent } from "../fixtures/mock-data";

test.describe("Pacing settings", () => {
  test("shows fatigue and elevation sliders", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    const fatigueSlider = mockedPage.getByRole("slider", {
      name: "Indice de fatigue accumulée",
    });
    const elevationSlider = mockedPage.getByRole("slider", {
      name: "Indice de dénivelé",
    });
    await expect(fatigueSlider).toBeVisible();
    await expect(elevationSlider).toBeVisible();
  });

  test("fatigue slider defaults to 10%", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    const fatigueSlider = mockedPage.getByRole("slider", {
      name: "Indice de fatigue accumulée",
    });
    // Default fatigueFactor is 0.9, which is 10%
    await expect(fatigueSlider).toHaveValue("10");
  });
});
