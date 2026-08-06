import { expect } from "@playwright/test";
import { Given, When, Then } from "../support/fixtures";

// ---------------------------------------------------------------------------
// Guided in-ride search (#935) — FR + EN. Drives the same public fixtures as
// the mocked spec (in-ride-search) so the scenarios run end-to-end. The trip
// creation itself reuses the shared "full trip with 3 stages" Given.
// ---------------------------------------------------------------------------

const GEO = { latitude: 44.7, longitude: 4.6 };

async function shareLocation(
  page: import("@playwright/test").Page,
): Promise<void> {
  await page
    .context()
    .grantPermissions(["geolocation"], { origin: "https://localhost" });
  await page.context().setGeolocation(GEO);
}

async function openPanel(page: import("@playwright/test").Page): Promise<void> {
  const bubble = page.getByTestId("in-ride-bubble");
  await expect(bubble).toBeVisible({ timeout: 10_000 });
  await bubble.click();
  await expect(page.getByTestId("in-ride-panel")).toBeVisible();
}

async function goOffline(page: import("@playwright/test").Page): Promise<void> {
  await page.context().setOffline(true);
  await page.evaluate(() => {
    Object.defineProperty(navigator, "onLine", {
      configurable: true,
      get: () => false,
    });
    window.dispatchEvent(new Event("offline"));
  });
}

Given("I have shared my location", async ({ mockedPage }) => {
  await shareLocation(mockedPage);
});

Given("j'ai partagé ma position", async ({ mockedPage }) => {
  await shareLocation(mockedPage);
});

When("I open the in-ride panel", async ({ mockedPage }) => {
  await openPanel(mockedPage);
});

When("j'ouvre le panneau en route", async ({ mockedPage }) => {
  await openPanel(mockedPage);
});

Then("the eight in-ride question chips are visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("in-ride-chips")).toBeVisible();
  const chips = mockedPage.locator('[data-testid^="in-ride-chip-"]');
  await expect(chips).toHaveCount(8);
});

Then(
  "les huit puces de questions en route sont visibles",
  async ({ mockedPage }) => {
    await expect(mockedPage.getByTestId("in-ride-chips")).toBeVisible();
    const chips = mockedPage.locator('[data-testid^="in-ride-chip-"]');
    await expect(chips).toHaveCount(8);
  },
);

When(
  "I tap the {string} in-ride question chip",
  async ({ mockedPage }, category: string) => {
    await mockedPage.getByTestId(`in-ride-chip-${category}`).click();
  },
);

When(
  "je touche la puce de question en route {string}",
  async ({ mockedPage }, category: string) => {
    await mockedPage.getByTestId(`in-ride-chip-${category}`).click();
  },
);

When("I widen the in-ride search", async ({ mockedPage }) => {
  await mockedPage.getByTestId("in-ride-widen").click();
});

When("j'élargis la recherche en route", async ({ mockedPage }) => {
  await mockedPage.getByTestId("in-ride-widen").click();
});

Then("an in-ride recap is shown", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("in-ride-recap").first()).toBeVisible();
});

Then("un récapitulatif en route est affiché", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("in-ride-recap").first()).toBeVisible();
});

Then("nearby in-ride POI cards are shown", async ({ mockedPage }) => {
  await expect(
    mockedPage.getByTestId("in-ride-pois").getByTestId("poi-card").first(),
  ).toBeVisible();
});

Then(
  "des cartes de POI en route à proximité sont affichées",
  async ({ mockedPage }) => {
    await expect(
      mockedPage.getByTestId("in-ride-pois").getByTestId("poi-card").first(),
    ).toBeVisible();
  },
);

When("the app goes offline", async ({ mockedPage }) => {
  await goOffline(mockedPage);
});

When("l'application passe hors ligne", async ({ mockedPage }) => {
  await goOffline(mockedPage);
});

Then("the in-ride bubble is disabled", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("in-ride-bubble")).toBeDisabled();
});

Then("la bulle en route est désactivée", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("in-ride-bubble")).toBeDisabled();
});

Then("the in-ride offline badge is visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("chat-offline-badge")).toBeVisible();
});

Then("le badge hors ligne en route est visible", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("chat-offline-badge")).toBeVisible();
});
