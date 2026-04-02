import { expect } from "@playwright/test";
import { Given, When, Then } from "../support/fixtures";
import { getTripId } from "../../fixtures/api-mocks";

// ---------------------------------------------------------------------------
// Sharing — FR + EN
// ---------------------------------------------------------------------------

Given("aucun lien de partage n'est actif", async ({ mockedPage }) => {
  await mockedPage.route(`**/trips/${getTripId()}/share`, (route, request) => {
    if (request.method() !== "GET") return route.fallback();
    return route.fulfill({ status: 404, body: "" });
  });
  await mockedPage.route(`**/trips/${getTripId()}/share`, (route, request) => {
    if (request.method() !== "POST") return route.fallback();
    return route.fulfill({
      status: 201,
      contentType: "application/ld+json",
      body: JSON.stringify({
        shortCode: "NewCode1",
        token: "new-token",
        createdAt: new Date().toISOString(),
      }),
    });
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
  await expect(mockedPage.getByTestId("share-link-text")).toBeVisible({
    timeout: 5000,
  });
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
  await expect(mockedPage.getByTestId("share-link-text")).not.toBeVisible();
});

Then("the share link is no longer visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("share-link-text")).not.toBeVisible();
});

Then(
  "le bouton {string} s'affiche",
  async ({ mockedPage }, btnTestId: string) => {
    await expect(mockedPage.getByTestId("share-create-link-button")).toBeVisible(
      { timeout: 5000 },
    );
  },
);

Then(
  "the {string} button is displayed",
  async ({ mockedPage }, _btnName: string) => {
    await expect(mockedPage.getByTestId("share-create-link-button")).toBeVisible(
      { timeout: 5000 },
    );
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

Then(
  "the short link is copied to the clipboard",
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
