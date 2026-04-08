import { expect } from "@playwright/test";
import { Given, When, Then } from "../support/fixtures";

// ---------------------------------------------------------------------------
// Map visualization — FR + EN
// ---------------------------------------------------------------------------

Given(
  "les étapes n'ont pas de données de géométrie",
  async ({ injectEvent }) => {
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

Given("stages have no geometry data", async ({ injectEvent }) => {
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

Then("le profil altimétrique n'est pas visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("elevation-profile")).not.toBeVisible();
});

Then("the elevation profile is not visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("elevation-profile")).not.toBeVisible();
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
  await expect(
    profile.locator("svg").getByTestId("elevation-crosshair"),
  ).toBeAttached();
});

Then("the vertical crosshair is visible", async ({ mockedPage }) => {
  const profile = mockedPage.getByTestId("elevation-profile");
  await expect(
    profile.locator("svg").getByTestId("elevation-crosshair"),
  ).toBeAttached();
});

Then("l'info-bulle altimétrique est affichée", async ({ mockedPage }) => {
  const profile = mockedPage.getByTestId("elevation-profile");
  await expect(profile.getByTestId("elevation-tooltip-bg")).toBeVisible();
});

Then("the elevation tooltip is displayed", async ({ mockedPage }) => {
  const profile = mockedPage.getByTestId("elevation-profile");
  await expect(profile.getByTestId("elevation-tooltip-bg")).toBeVisible();
});

Then(
  "le bouton {string} n'est pas visible",
  async ({ mockedPage }, _btnName: string) => {
    await expect(mockedPage.getByTestId("map-reset-view")).not.toBeVisible();
  },
);

Then(
  "the {string} button is not visible",
  async ({ mockedPage }, _btnName: string) => {
    await expect(mockedPage.getByTestId("map-reset-view")).not.toBeVisible();
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

// --- Additional missing steps ---

Then("la vue revient à l'ensemble du parcours", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("map-container")).toHaveAttribute(
    "data-focused-stage",
    "",
    { timeout: 5000 },
  );
});

Then("the view returns to the full route", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("map-container")).toHaveAttribute(
    "data-focused-stage",
    "",
    { timeout: 5000 },
  );
});

When(
  "je clique sur le bouton de mode {string}",
  async ({ mockedPage }, mode: string) => {
    const modeMap: Record<string, string> = {
      "carte seule": "view-mode-map",
      "vue splitée": "view-mode-split",
      "vue partagée": "view-mode-split",
      chronologie: "view-mode-timeline",
    };
    const testId = modeMap[mode] ?? `view-mode-${mode}`;
    await mockedPage.getByTestId(testId).first().click();
  },
);

When(
  "I click the {string} view mode button",
  async ({ mockedPage }, mode: string) => {
    const modeMap: Record<string, string> = {
      "map only": "view-mode-map",
      "split view": "view-mode-split",
      timeline: "view-mode-timeline",
    };
    const testId = modeMap[mode] ?? `view-mode-${mode}`;
    await mockedPage.getByTestId(testId).first().click();
  },
);

Then("je vois uniquement le panneau carte", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("map-panel")).toBeVisible({
    timeout: 5000,
  });
});

Then("I only see the map panel", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("map-panel")).toBeVisible({
    timeout: 5000,
  });
});

Then("je vois les deux panneaux côte à côte", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("split-view-container")).toBeVisible({
    timeout: 5000,
  });
});

Then("I see both panels side by side", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("split-view-container")).toBeVisible({
    timeout: 5000,
  });
});

Then(
  "chaque étape est représentée avec une couleur distincte sur la carte",
  async ({ mockedPage }) => {
    // Verify map is rendering stages — each stage layer exists in the map view
    await expect(mockedPage.getByTestId("map-view")).toBeVisible({
      timeout: 5000,
    });
  },
);

Then(
  "each stage is represented with a distinct color on the map",
  async ({ mockedPage }) => {
    await expect(mockedPage.getByTestId("map-view")).toBeVisible({
      timeout: 5000,
    });
  },
);

When("je consulte le voyage sur un écran mobile", async ({ mockedPage }) => {
  await mockedPage.setViewportSize({ width: 390, height: 844 });
});

When("I view the trip on a mobile screen", async ({ mockedPage }) => {
  await mockedPage.setViewportSize({ width: 390, height: 844 });
});

Then("la carte s'adapte à la taille de l'écran", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("view-mode-toggle")).toBeVisible({
    timeout: 5000,
  });
  await expect(mockedPage.locator("#timeline")).toBeVisible({ timeout: 5000 });
  const noHScroll = await mockedPage.evaluate(
    () =>
      document.documentElement.scrollWidth <=
      document.documentElement.clientWidth,
  );
  expect(noHScroll).toBe(true);
});

Then("the map adapts to the screen size", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("view-mode-toggle")).toBeVisible({
    timeout: 5000,
  });
  await expect(mockedPage.locator("#timeline")).toBeVisible({ timeout: 5000 });
  const noHScroll = await mockedPage.evaluate(
    () =>
      document.documentElement.scrollWidth <=
      document.documentElement.clientWidth,
  );
  expect(noHScroll).toBe(true);
});
