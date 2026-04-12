import { expect } from "@playwright/test";
import { Given, When, Then } from "../support/fixtures";
import {
  emptyAccommodationsFoundEvent,
  accommodationsFoundEvent,
} from "../../fixtures/mock-data";
import { injectSseSequence } from "../../fixtures/sse-helpers";
import { takeAccommodationScanRequest } from "../support/accommodation-scan-tracker";
import { getCurrentRecettePage } from "../support/current-recette-page";

function getAccommodationTextAlias(text: string): string {
  const aliases: Record<string, string> = {
    Hôtel: "Hotel",
    "Ajouter un hébergement": "Add accommodation",
  };

  return aliases[text] ?? text;
}

// ---------------------------------------------------------------------------
// Accommodations — FR + EN
// ---------------------------------------------------------------------------

Given(
  "aucun hébergement n'est trouvé dans un rayon de {int} km pour l'étape {int}",
  async ({ injectSequence }, radius: number, _stage: number) => {
    await injectSequence([emptyAccommodationsFoundEvent(0, radius)]);
  },
);

Given(
  "no accommodation is found within {int} km for stage {int}",
  async ({ injectSequence }, radius: number, _stage: number) => {
    await injectSequence([emptyAccommodationsFoundEvent(0, radius)]);
  },
);

Given(
  "des hébergements sont trouvés dans un rayon de {int} km",
  async ({ injectSequence }, radius: number) => {
    await injectSequence([accommodationsFoundEvent(0, radius)]);
  },
);

Given(
  "accommodations are found within a {int} km radius",
  async ({ injectSequence }, radius: number) => {
    await injectSequence([accommodationsFoundEvent(0, radius)]);
  },
);

Then(
  "la carte de l'étape {int} affiche {string}",
  async ({ mockedPage }, n: number, text: string) => {
    await expect(mockedPage.getByTestId(`stage-card-${n}`)).toContainText(
      text,
      { timeout: 10000 },
    );
  },
);

Then(
  "stage card {int} shows {string}",
  async ({ mockedPage }, n: number, text: string) => {
    await expect(mockedPage.getByTestId(`stage-card-${n}`)).toContainText(
      text,
      { timeout: 10000 },
    );
  },
);

Then(
  "la carte de l'étape {int} affiche le label {string}",
  async ({ mockedPage }, n: number, label: string) => {
    await expect(mockedPage.getByTestId(`stage-card-${n}`)).toContainText(
      label,
    );
  },
);

Then(
  "stage card {int} shows the label {string}",
  async ({ mockedPage }, n: number, label: string) => {
    await expect(mockedPage.getByTestId(`stage-card-${n}`)).toContainText(
      getAccommodationTextAlias(label),
    );
  },
);

Then(
  "la carte de l'étape {int} affiche la distance {string}",
  async ({ mockedPage }, n: number, dist: string) => {
    await expect(mockedPage.getByTestId(`stage-card-${n}`)).toContainText(dist);
  },
);

Then(
  "stage card {int} shows the distance {string}",
  async ({ mockedPage }, n: number, dist: string) => {
    await expect(mockedPage.getByTestId(`stage-card-${n}`)).toContainText(dist);
  },
);

When(
  "je clique sur {string} sur la carte de l'étape {int}",
  async ({ mockedPage }, btnText: string, n: number) => {
    const stageCard = mockedPage.getByTestId(`stage-card-${n}`);
    await stageCard.getByRole("button", { name: btnText }).click();
  },
);

When(
  "I click {string} on stage card {int}",
  async ({ mockedPage }, btnText: string, n: number) => {
    const stageCard = mockedPage.getByTestId(`stage-card-${n}`);
    await stageCard
      .getByRole("button", { name: getAccommodationTextAlias(btnText) })
      .click();
  },
);

Then(
  "le formulaire d'ajout d'hébergement s'affiche",
  async ({ mockedPage }) => {
    await expect(
      mockedPage.getByRole("textbox", { name: "Nom de l'hébergement" }),
    ).toBeVisible();
  },
);

Then("the add accommodation form appears", async ({ mockedPage }) => {
  await expect(
    mockedPage.getByRole("textbox", {
      name: /Accommodation name|Nom de l'hébergement/,
    }),
  ).toBeVisible();
});

When(
  "je supprime {string} sur la carte de l'étape {int}",
  async ({ mockedPage }, name: string, n: number) => {
    const stageCard = mockedPage.getByTestId(`stage-card-${n}`);
    const removeButtons = stageCard.getByRole("button", {
      name: "Supprimer l'hébergement",
    });
    // Find the one near the named accommodation (click first matching)
    await removeButtons.first().click();
  },
);

When(
  "I remove {string} from stage card {int}",
  async ({ mockedPage }, _name: string, n: number) => {
    const stageCard = mockedPage.getByTestId(`stage-card-${n}`);
    const removeButtons = stageCard.getByRole("button", {
      name: /Remove accommodation|Supprimer l'hébergement/,
    });
    await removeButtons.first().click();
  },
);

Then(
  "{string} n'apparaît plus sur la carte de l'étape {int}",
  async ({ mockedPage }, name: string, n: number) => {
    await expect(mockedPage.getByTestId(`stage-card-${n}`)).not.toContainText(
      name,
    );
  },
);

Then(
  "{string} no longer appears on stage card {int}",
  async ({ mockedPage }, name: string, n: number) => {
    await expect(mockedPage.getByTestId(`stage-card-${n}`)).not.toContainText(
      name,
    );
  },
);

Then(
  "la carte de la dernière étape n'affiche pas le bouton {string}",
  async ({ mockedPage }, btnName: string) => {
    const lastStage = mockedPage.getByTestId("stage-card-3");
    await expect(lastStage.getByRole("button", { name: btnName })).toBeHidden();
  },
);

Then(
  "the last stage card does not show the {string} button",
  async ({ mockedPage }, btnName: string) => {
    const lastStage = mockedPage.getByTestId("stage-card-3");
    await expect(
      lastStage.getByRole("button", {
        name: getAccommodationTextAlias(btnName),
      }),
    ).toBeHidden();
  },
);

Then(
  "je vois un message indiquant un rayon de {int} km",
  async ({ mockedPage }, radius: number) => {
    await expect(mockedPage.getByTestId("stage-card-1")).toContainText(
      `${radius} km`,
    );
  },
);

Then(
  "I see a message indicating a {int} km radius",
  async ({ mockedPage }, radius: number) => {
    await expect(mockedPage.getByTestId("stage-card-1")).toContainText(
      `${radius} km`,
    );
  },
);

Then(
  "je vois un bouton pour élargir à {int} km",
  async ({ mockedPage }, radius: number) => {
    await expect(
      mockedPage.getByTestId("stage-card-1").getByRole("button", {
        name: new RegExp(`${radius} km`),
      }),
    ).toBeVisible();
  },
);

Then(
  "I see a button to expand to {int} km",
  async ({ mockedPage }, radius: number) => {
    await expect(
      mockedPage.getByTestId("stage-card-1").getByRole("button", {
        name: new RegExp(`${radius} km`),
      }),
    ).toBeVisible();
  },
);

Then(
  "le bouton d'élargissement à {int} km n'est pas visible",
  async ({ mockedPage }, radius: number) => {
    await expect(
      mockedPage.getByTestId("stage-card-1").getByRole("button", {
        name: new RegExp(`${radius} km`),
      }),
    ).toBeHidden();
  },
);

Then(
  "the button to expand to {int} km is not visible",
  async ({ mockedPage }, radius: number) => {
    await expect(
      mockedPage.getByTestId("stage-card-1").getByRole("button", {
        name: new RegExp(`${radius} km`),
      }),
    ).toBeHidden();
  },
);

Then(
  "une requête de scan avec radiusKm={int} est envoyée",
  async ({}, radius: number) => {
    const request = await takeAccommodationScanRequest();
    const body = JSON.parse(request.postData() ?? "{}");
    expect(body).toMatchObject({ radiusKm: radius });
  },
);

Then(
  "a scan request with radiusKm={int} is sent",
  async ({}, radius: number) => {
    const request = await takeAccommodationScanRequest();
    const body = JSON.parse(request.postData() ?? "{}");
    expect(body).toMatchObject({ radiusKm: radius });
  },
);

// --- Additional missing steps ---

When(
  "aucun hébergement n'est trouvé dans un rayon de {int} km",
  async ({ injectSequence }, radius: number) => {
    await injectSequence([emptyAccommodationsFoundEvent(0, radius)]);
  },
);

When(
  "no accommodation is found within {int} km",
  async ({}, radius: number) => {
    await injectSseSequence(getCurrentRecettePage(), [
      emptyAccommodationsFoundEvent(0, radius),
    ]);
  },
);

When(
  "un hébergement est exactement sur le point d'arrivée",
  async ({ injectSequence }) => {
    await injectSequence([
      {
        type: "accommodations_found",
        data: {
          stageIndex: 0,
          searchRadiusKm: 5,
          accommodations: [
            {
              name: "Gîte du Terminus",
              type: "guest_house",
              lat: 44.532,
              lon: 4.392,
              estimatedPriceMin: 45,
              estimatedPriceMax: 60,
              isExactPrice: false,
              possibleClosed: false,
              distanceToEndPoint: 0,
            },
          ],
        },
      },
    ]);
  },
);

When("an accommodation is exactly at the endpoint", async () => {
  await injectSseSequence(getCurrentRecettePage(), [
    {
      type: "accommodations_found",
      data: {
        stageIndex: 0,
        searchRadiusKm: 5,
        accommodations: [
          {
            name: "Gîte du Terminus",
            type: "guest_house",
            lat: 44.532,
            lon: 4.392,
            estimatedPriceMin: 45,
            estimatedPriceMax: 60,
            isExactPrice: false,
            possibleClosed: false,
            distanceToEndPoint: 0,
          },
        ],
      },
    },
  ]);
});

Then(
  "aucun badge de distance n'est affiché pour cet hébergement",
  async ({ mockedPage }) => {
    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard).toContainText("Gîte du Terminus", {
      timeout: 5000,
    });
    // When distanceToEndPoint is 0, no "X.X km" badge should be shown for that accommodation
    await expect(stageCard.locator('[aria-label*="0 km"]')).toHaveCount(0);
  },
);

Then("no distance badge is displayed for that accommodation", async () => {
  const stageCard = getCurrentRecettePage().getByTestId("stage-card-1");
  await expect(stageCard).toContainText(
    /Gîte du Terminus|Terminus Guest House/,
    {
      timeout: 5000,
    },
  );
  await expect(stageCard.locator('[aria-label*="0 km"]')).toHaveCount(0);
});
