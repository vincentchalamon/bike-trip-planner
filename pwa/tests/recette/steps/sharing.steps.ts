import { expect } from "@playwright/test";
import { recordedClipboard } from "../support/clipboard";
import { Given, When, Then } from "../support/fixtures";
import { getTripId } from "../../fixtures/api-mocks";
import { seededShare } from "../../fixtures/shared-trip-seed";
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
    const origin = new URL(mockedPage.url()).origin;
    await expect
      .poll(() => recordedClipboard(mockedPage))
      .toContain(`${origin}/s/`);
  },
);

Then("the short link is copied to the clipboard", async ({ mockedPage }) => {
  const origin = new URL(mockedPage.url()).origin;
  await expect
    .poll(() => recordedClipboard(mockedPage))
    .toContain(`${origin}/s/`);
});

// i18n-equivalent button names (feature files may use FR text even in EN scenarios)
const BUTTON_NAME_ALTERNATIVES: Record<string, RegExp> = {
  "Recevoir un lien de connexion":
    /Recevoir un lien de connexion|Send sign-in link/i,
};

// --- Additional missing steps ---

Then("je vois le bouton {string}", async ({ mockedPage }, btnName: string) => {
  const testId = SHARE_BUTTON_TESTID[btnName];
  if (testId) {
    await expect(mockedPage.getByTestId(testId)).toBeVisible({ timeout: 5000 });
  } else {
    const pattern = BUTTON_NAME_ALTERNATIVES[btnName] ?? btnName;
    await expect(mockedPage.getByRole("button", { name: pattern })).toBeVisible(
      { timeout: 5000 },
    );
  }
});

Then("I see the {string} button", async ({ mockedPage }, btnName: string) => {
  const testId = SHARE_BUTTON_TESTID[btnName];
  if (testId) {
    await expect(mockedPage.getByTestId(testId)).toBeVisible({ timeout: 5000 });
  } else {
    const pattern = BUTTON_NAME_ALTERNATIVES[btnName] ?? btnName;
    await expect(mockedPage.getByRole("button", { name: pattern })).toBeVisible(
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
    await expect
      .poll(() => recordedClipboard(mockedPage))
      .toContain("Tour de l'Ardeche");
  },
);

Then(
  "the summary text containing the trip title is copied",
  async ({ mockedPage }) => {
    await expect
      .poll(() => recordedClipboard(mockedPage))
      .toContain("Tour de l'Ardeche");
  },
);

// The page fetches the share server-side, out of `page.route()`'s reach: these
// steps open the real share seeded by the Playwright globalSetup.
When(/^j'accède à \/s\/<code_court>$/, async ({ $test, mockedPage }) => {
  const share = seededShare();
  $test.skip(!share, "No seeded share: set E2E_JWT (see shared-trip-seed.ts).");
  await mockedPage.goto(`/s/${share!.code}`);
});

When(/^I navigate to \/s\/<short_code>$/, async ({ $test, mockedPage }) => {
  const share = seededShare();
  $test.skip(!share, "No seeded share: set E2E_JWT (see shared-trip-seed.ts).");
  await mockedPage.goto(`/s/${share!.code}`);
});

Then("je vois le résumé du voyage partagé", async ({ mockedPage }) => {
  await expect(
    mockedPage.getByRole("heading", { name: seededShare()!.title }),
  ).toBeVisible({ timeout: 10000 });
  await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible();
});

Then("I see the shared trip summary", async ({ mockedPage }) => {
  await expect(
    mockedPage.getByRole("heading", { name: seededShare()!.title }),
  ).toBeVisible({ timeout: 10000 });
  await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible();
});
