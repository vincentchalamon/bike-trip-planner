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

  test("shows max distance and average speed sliders", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    const maxDistanceSlider = mockedPage.getByRole("slider", {
      name: "Distance maximale par jour (km)",
    });
    const averageSpeedSlider = mockedPage.getByRole("slider", {
      name: "Vitesse moyenne (km/h)",
    });
    await expect(maxDistanceSlider).toBeVisible();
    await expect(averageSpeedSlider).toBeVisible();
  });

  test("max distance slider defaults to 80 km", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    const maxDistanceSlider = mockedPage.getByRole("slider", {
      name: "Distance maximale par jour (km)",
    });
    await expect(maxDistanceSlider).toHaveValue("80");
  });

  test("average speed slider defaults to 15 km/h", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    const averageSpeedSlider = mockedPage.getByRole("slider", {
      name: "Vitesse moyenne (km/h)",
    });
    await expect(averageSpeedSlider).toHaveValue("15");
  });

  test("shows preset buttons", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    const beginnerButton = mockedPage.getByRole("button", {
      name: "Appliquer le profil Débutant",
    });
    const intermediateButton = mockedPage.getByRole("button", {
      name: "Appliquer le profil Intermédiaire",
    });
    const expertButton = mockedPage.getByRole("button", {
      name: "Appliquer le profil Expert",
    });
    await expect(beginnerButton).toBeVisible();
    await expect(intermediateButton).toBeVisible();
    await expect(expertButton).toBeVisible();
  });

  test("clicking Débutant preset sets distance=50 and speed=10", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    const beginnerButton = mockedPage.getByRole("button", {
      name: "Appliquer le profil Débutant",
    });
    await beginnerButton.click();
    const maxDistanceSlider = mockedPage.getByRole("slider", {
      name: "Distance maximale par jour (km)",
    });
    const averageSpeedSlider = mockedPage.getByRole("slider", {
      name: "Vitesse moyenne (km/h)",
    });
    await expect(maxDistanceSlider).toHaveValue("50");
    await expect(averageSpeedSlider).toHaveValue("10");
  });

  test("shows coherence warning when speed < 8 and distance > 100", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    const expertButton = mockedPage.getByRole("button", {
      name: "Appliquer le profil Expert",
    });
    // First set distance > 100 via expert preset (120 km)
    await expertButton.click();
    // Then manually set speed below 8 by filling the average speed slider
    const averageSpeedSlider = mockedPage.getByRole("slider", {
      name: "Vitesse moyenne (km/h)",
    });
    await averageSpeedSlider.fill("7");
    await expect(
      mockedPage.getByRole("alert").filter({ hasText: "vitesse" }),
    ).toBeVisible();
  });
});
