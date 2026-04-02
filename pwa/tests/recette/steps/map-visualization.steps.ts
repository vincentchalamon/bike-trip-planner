import { expect } from "@playwright/test";
import { Given, When, Then } from "../support/fixtures";

// ---------------------------------------------------------------------------
// Map visualization — FR + EN
// ---------------------------------------------------------------------------

Given(
  "les étapes n'ont pas de données de géométrie",
  async ({ submitUrl, injectEvent }) => {
    await submitUrl();
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
        ],
      },
    });
  },
);

Given("stages have no geometry data", async ({ submitUrl, injectEvent }) => {
  await submitUrl();
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
      ],
    },
  });
});

Then(
  "la vue MapLibre est visible dans le panneau carte",
  async ({ mockedPage }) => {
    await expect(mockedPage.getByTestId("map-container")).toBeVisible({
      timeout: 5000,
    });
    await expect(mockedPage.getByTestId("map-view")).toBeVisible({
      timeout: 5000,
    });
  },
);

Then(
  "the MapLibre view is visible in the map panel",
  async ({ mockedPage }) => {
    await expect(mockedPage.getByTestId("map-container")).toBeVisible({
      timeout: 5000,
    });
    await expect(mockedPage.getByTestId("map-view")).toBeVisible({
      timeout: 5000,
    });
  },
);

Then(
  "le profil altimétrique n'est pas visible",
  async ({ mockedPage }) => {
    await expect(
      mockedPage.getByTestId("elevation-profile"),
    ).not.toBeVisible();
  },
);

Then("the elevation profile is not visible", async ({ mockedPage }) => {
  await expect(
    mockedPage.getByTestId("elevation-profile"),
  ).not.toBeVisible();
});

When("je survole le profil altimétrique", async ({ mockedPage }) => {
  await mockedPage
    .getByTestId("view-mode-toggle")
    .getByTestId("view-mode-map")
    .click();
  const profile = mockedPage.getByTestId("elevation-profile");
  await expect(profile).toBeVisible({ timeout: 5000 });
  await profile.locator("svg").hover();
});

When("I hover over the elevation profile", async ({ mockedPage }) => {
  await mockedPage
    .getByTestId("view-mode-toggle")
    .getByTestId("view-mode-map")
    .click();
  const profile = mockedPage.getByTestId("elevation-profile");
  await expect(profile).toBeVisible({ timeout: 5000 });
  await profile.locator("svg").hover();
});

Then("le réticule vertical est visible", async ({ mockedPage }) => {
  const profile = mockedPage.getByTestId("elevation-profile");
  await expect(profile.locator("svg").getByTestId("elevation-crosshair")).toBeAttached();
});

Then("the vertical crosshair is visible", async ({ mockedPage }) => {
  const profile = mockedPage.getByTestId("elevation-profile");
  await expect(profile.locator("svg").getByTestId("elevation-crosshair")).toBeAttached();
});

Then("l'info-bulle altimétrique est affichée", async ({ mockedPage }) => {
  const profile = mockedPage.getByTestId("elevation-profile");
  await expect(
    profile.getByTestId("elevation-tooltip-bg"),
  ).toBeVisible();
});

Then("the elevation tooltip is displayed", async ({ mockedPage }) => {
  const profile = mockedPage.getByTestId("elevation-profile");
  await expect(
    profile.getByTestId("elevation-tooltip-bg"),
  ).toBeVisible();
});

Then(
  "le bouton {string} n'est pas visible",
  async ({ mockedPage }, btnName: string) => {
    await expect(
      mockedPage.getByTestId("map-reset-view"),
    ).not.toBeVisible();
  },
);

Then(
  "the {string} button is not visible",
  async ({ mockedPage }, _btnName: string) => {
    await expect(
      mockedPage.getByTestId("map-reset-view"),
    ).not.toBeVisible();
  },
);

When(
  "je sélectionne l'étape {int} sur la carte",
  async ({ mockedPage }, n: number) => {
    await mockedPage.evaluate((stageIndex: number) => {
      window.dispatchEvent(
        new CustomEvent("__test_set_focused_map_stage", {
          detail: stageIndex - 1,
        }),
      );
    }, n);
  },
);

When("I select stage {int} on the map", async ({ mockedPage }, n: number) => {
  await mockedPage.evaluate((stageIndex: number) => {
    window.dispatchEvent(
      new CustomEvent("__test_set_focused_map_stage", {
        detail: stageIndex - 1,
      }),
    );
  }, n);
});

Then(
  "le bouton {string} est visible",
  async ({ mockedPage }, _btnName: string) => {
    await expect(mockedPage.getByTestId("map-reset-view")).toBeVisible({
      timeout: 3000,
    });
  },
);

Then(
  "the {string} button is visible",
  async ({ mockedPage }, _btnName: string) => {
    await expect(mockedPage.getByTestId("map-reset-view")).toBeVisible({
      timeout: 3000,
    });
  },
);
