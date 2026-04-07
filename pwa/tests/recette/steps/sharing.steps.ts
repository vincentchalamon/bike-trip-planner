import { expect } from "@playwright/test";
import { Given, When, Then } from "../support/fixtures";
import { getTripId } from "../../fixtures/api-mocks";
import { SHARE_BUTTON_TESTID } from "./common.steps";

// ---------------------------------------------------------------------------
// Sharing — FR + EN
// ---------------------------------------------------------------------------

Given("aucun lien de partage n'est actif", async ({ mockedPage }) => {
  await mockedPage.route(`**/trips/${getTripId()}/share`, (route, request) => {
    if (request.method() === "GET") {
      return route.fulfill({ status: 404, body: "" });
    }
    if (request.method() === "POST") {
      return route.fulfill({
        status: 201,
        contentType: "application/ld+json",
        body: JSON.stringify({
          shortCode: "NewCode1",
          token: "new-token",
          createdAt: new Date().toISOString(),
        }),
      });
    }
    return route.fallback();
  });
});

Given("no share link is active", async ({ mockedPage }) => {
  await mockedPage.route(`**/trips/${getTripId()}/share`, (route, request) => {
    if (request.method() === "GET") {
      return route.fulfill({ status: 404, body: "" });
    }
    if (request.method() === "POST") {
      return route.fulfill({
        status: 201,
        contentType: "application/ld+json",
        body: JSON.stringify({
          shortCode: "NewCode1",
          token: "new-token",
          createdAt: new Date().toISOString(),
        }),
      });
    }
    return route.fallback();
  });
});

When("j'ouvre la modale de partage", async ({ mockedPage }) => {
  await mockedPage.getByTestId("share-button").click();
  await expect(
    mockedPage
      .getByTestId("share-link-text")
      .or(mockedPage.getByTestId("share-create-link-button")),
  ).toBeVisible({ timeout: 5000 });
});

When("I open the share modal", async ({ mockedPage }) => {
  await mockedPage.getByTestId("share-button").click();
  await expect(
    mockedPage
      .getByTestId("share-link-text")
      .or(mockedPage.getByTestId("share-create-link-button")),
  ).toBeVisible({ timeout: 5000 });
});

When("je révoque le lien", async ({ mockedPage }) => {
  await mockedPage.route(`**/trips/${getTripId()}/share`, (route, request) => {
    if (request.method() !== "DELETE") return route.fallback();
    return route.fulfill({ status: 204, body: "" });
  });
  await mockedPage.getByTestId("share-revoke-link-button").click();
  await expect(mockedPage.getByTestId("share-create-link-button")).toBeVisible({
    timeout: 5000,
  });
});

When("I revoke the link", async ({ mockedPage }) => {
  await mockedPage.route(`**/trips/${getTripId()}/share`, (route, request) => {
    if (request.method() !== "DELETE") return route.fallback();
    return route.fulfill({ status: 204, body: "" });
  });
  await mockedPage.getByTestId("share-revoke-link-button").click();
  await expect(mockedPage.getByTestId("share-create-link-button")).toBeVisible({
    timeout: 5000,
  });
});

Then("je vois le lien de partage court", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("share-link-text")).toBeVisible();
});

Then("I see the short share link", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("share-link-text")).toBeVisible();
});

Then("le lien de partage n'est plus visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("share-link-text")).not.toBeVisible({
    timeout: 5000,
  });
});

Then("the share link is no longer visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("share-link-text")).not.toBeVisible({
    timeout: 5000,
  });
});

Then(
  "le bouton {string} s'affiche",
  async ({ mockedPage }, btnName: string) => {
    const testId = SHARE_BUTTON_TESTID[btnName];
    if (testId) {
      await expect(mockedPage.getByTestId(testId)).toBeVisible({
        timeout: 5000,
      });
    } else {
      await expect(
        mockedPage.getByRole("button", { name: btnName }),
      ).toBeVisible({ timeout: 5000 });
    }
  },
);

Then(
  "the {string} button is displayed",
  async ({ mockedPage }, btnName: string) => {
    const testId = SHARE_BUTTON_TESTID[btnName];
    if (testId) {
      await expect(mockedPage.getByTestId(testId)).toBeVisible({
        timeout: 5000,
      });
    } else {
      await expect(
        mockedPage.getByRole("button", { name: btnName }),
      ).toBeVisible({ timeout: 5000 });
    }
  },
);

Then("un nouveau lien de partage est généré", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("share-link-text")).toBeVisible({
    timeout: 5000,
  });
});

Then("a new share link is generated", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("share-link-text")).toBeVisible({
    timeout: 5000,
  });
});

Then("le lien n'est pas encore visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("share-link-text")).not.toBeVisible();
});

Then("the link is not yet visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("share-link-text")).not.toBeVisible();
});

Then(
  "le lien court est copié dans le presse-papiers",
  async ({ mockedPage }) => {
    await mockedPage
      .context()
      .grantPermissions(["clipboard-read", "clipboard-write"]);
    const clipboardText = await mockedPage.evaluate(() =>
      navigator.clipboard.readText(),
    );
    const origin = new URL(mockedPage.url()).origin;
    expect(clipboardText).toContain(`${origin}/s/`);
  },
);

Then("the short link is copied to the clipboard", async ({ mockedPage }) => {
  await mockedPage
    .context()
    .grantPermissions(["clipboard-read", "clipboard-write"]);
  const clipboardText = await mockedPage.evaluate(() =>
    navigator.clipboard.readText(),
  );
  const origin = new URL(mockedPage.url()).origin;
  expect(clipboardText).toContain(`${origin}/s/`);
});

// --- Additional missing steps ---

Then("je vois le bouton {string}", async ({ mockedPage }, btnName: string) => {
  const testId = SHARE_BUTTON_TESTID[btnName];
  if (testId) {
    await expect(mockedPage.getByTestId(testId)).toBeVisible({ timeout: 5000 });
  } else {
    await expect(mockedPage.getByRole("button", { name: btnName })).toBeVisible(
      { timeout: 5000 },
    );
  }
});

Then("I see the {string} button", async ({ mockedPage }, btnName: string) => {
  const testId = SHARE_BUTTON_TESTID[btnName];
  if (testId) {
    await expect(mockedPage.getByTestId(testId)).toBeVisible({ timeout: 5000 });
  } else {
    await expect(mockedPage.getByRole("button", { name: btnName })).toBeVisible(
      { timeout: 5000 },
    );
  }
});

Then("un fichier PNG est téléchargé", async ({ mockedPage }) => {
  const downloadPromise = mockedPage.waitForEvent("download");
  await mockedPage.getByTestId("share-download-png-button").click();
  const download = await downloadPromise;
  expect(download.suggestedFilename()).toContain(".png");
});

Then("a PNG file is downloaded", async ({ mockedPage }) => {
  const downloadPromise = mockedPage.waitForEvent("download");
  await mockedPage.getByTestId("share-download-png-button").click();
  const download = await downloadPromise;
  expect(download.suggestedFilename()).toContain(".png");
});

Then(
  "le texte résumé contenant le titre du voyage est copié",
  async ({ mockedPage }) => {
    await mockedPage
      .context()
      .grantPermissions(["clipboard-read", "clipboard-write"]);
    const clipboardText = await mockedPage.evaluate(() =>
      navigator.clipboard.readText(),
    );
    expect(clipboardText).toContain("Tour de l'Ardeche");
  },
);

Then(
  "the summary text containing the trip title is copied",
  async ({ mockedPage }) => {
    await mockedPage
      .context()
      .grantPermissions(["clipboard-read", "clipboard-write"]);
    const clipboardText = await mockedPage.evaluate(() =>
      navigator.clipboard.readText(),
    );
    expect(clipboardText).toContain("Tour de l'Ardeche");
  },
);

When(/^j'accède à \/s\/<code_court>$/, async ({ mockedPage }) => {
  const shortCode = "Ab3kX9mP";
  await mockedPage.route(`**/s/${shortCode}`, (route, request) => {
    if (request.method() !== "GET") return route.fallback();
    return route.fulfill({
      status: 200,
      contentType: "application/ld+json",
      body: JSON.stringify({
        title: "Tour de l'Ardeche",
        startDate: null,
        endDate: null,
        fatigueFactor: 0.9,
        elevationPenalty: 50,
        maxDistancePerDay: 80,
        averageSpeed: 15,
        stages: [],
      }),
    });
  });
  await mockedPage.goto(`/s/${shortCode}`);
});

When(/^I navigate to \/s\/<short_code>$/, async ({ mockedPage }) => {
  const shortCode = "Ab3kX9mP";
  await mockedPage.route(`**/s/${shortCode}`, (route, request) => {
    if (request.method() !== "GET") return route.fallback();
    return route.fulfill({
      status: 200,
      contentType: "application/ld+json",
      body: JSON.stringify({
        title: "Tour de l'Ardeche",
        startDate: null,
        endDate: null,
        fatigueFactor: 0.9,
        elevationPenalty: 50,
        maxDistancePerDay: 80,
        averageSpeed: 15,
        stages: [],
      }),
    });
  });
  await mockedPage.goto(`/s/${shortCode}`);
});

Then("je vois le résumé du voyage partagé", async ({ mockedPage }) => {
  await expect(
    mockedPage
      .getByTestId("trip-title")
      .or(mockedPage.getByText("Tour de l'Ardeche")),
  ).toBeVisible({ timeout: 5000 });
});

Then("I see the shared trip summary", async ({ mockedPage }) => {
  await expect(
    mockedPage
      .getByTestId("trip-title")
      .or(mockedPage.getByText("Tour de l'Ardeche")),
  ).toBeVisible({ timeout: 5000 });
});
