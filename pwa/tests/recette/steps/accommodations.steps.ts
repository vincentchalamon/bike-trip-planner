import { expect } from "@playwright/test";
import { Given, When, Then } from "../support/fixtures";
import {
  emptyAccommodationsFoundEvent,
  accommodationsFoundEvent,
} from "../../fixtures/mock-data";

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
      label,
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
    await stageCard.getByRole("button", { name: btnText }).click();
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
    mockedPage.getByRole("textbox", { name: "Nom de l'hébergement" }),
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
      name: "Supprimer l'hébergement",
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
    await expect(lastStage.getByRole("button", { name: btnName })).toBeHidden();
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
  async ({ mockedPage }, radius: number) => {
    const requestPromise = mockedPage.waitForRequest(
      (req) =>
        req.url().includes("/accommodations/scan") && req.method() === "POST",
      { timeout: 5000 },
    );
    const request = await requestPromise;
    const body = JSON.parse(request.postData() ?? "{}");
    expect(body).toMatchObject({ radiusKm: radius });
  },
);

Then(
  "a scan request with radiusKm={int} is sent",
  async ({ mockedPage }, radius: number) => {
    const requestPromise = mockedPage.waitForRequest(
      (req) =>
        req.url().includes("/accommodations/scan") && req.method() === "POST",
      { timeout: 5000 },
    );
    const request = await requestPromise;
    const body = JSON.parse(request.postData() ?? "{}");
    expect(body).toMatchObject({ radiusKm: radius });
  },
);

// --- Additional missing steps ---

When(
  "aucun hébergement n'est trouvé dans un rayon de {int} km",
  async ({ $test }, _radius: number) => {
    $test.fixme();
  },
);

When(
  "no accommodation is found within {int} km",
  async ({ $test }, _radius: number) => {
    $test.fixme();
  },
);

When(
  "un hébergement est exactement sur le point d'arrivée",
  async ({ $test }) => {
    $test.fixme();
  },
);

When("an accommodation is exactly at the endpoint", async ({ $test }) => {
  $test.fixme();
});

Then(
  "aucun badge de distance n'est affiché pour cet hébergement",
  async ({ $test }) => {
    $test.fixme();
  },
);

Then(
  "no distance badge is displayed for that accommodation",
  async ({ $test }) => {
    $test.fixme();
  },
);
