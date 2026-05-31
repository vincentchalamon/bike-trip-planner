import { test, expect } from "../fixtures/base.fixture";
import { routeParsedEvent } from "../fixtures/mock-data";

/**
 * E2E coverage for the desktop top bar (#384).
 *
 * The `mockedPage` fixture authenticates via a fake JWT and lands on `/`,
 * where the TripPlanner (and therefore the TopBar) renders.
 */
test.describe("Desktop top bar", () => {
  test("renders the always-visible elements on the welcome screen", async ({
    mockedPage,
  }) => {
    await expect(mockedPage.getByTestId("top-bar")).toBeVisible();
    await expect(mockedPage.getByTestId("top-bar-brand")).toBeVisible();
    await expect(mockedPage.getByTestId("nav-new-trip")).toBeVisible();
    await expect(mockedPage.getByTestId("nav-my-trips")).toBeVisible();
    await expect(mockedPage.getByTestId("help-button")).toBeVisible();
    await expect(mockedPage.getByTestId("locale-switch-fr")).toBeVisible();
    await expect(mockedPage.getByTestId("locale-switch-en")).toBeVisible();
    await expect(mockedPage.getByTestId("theme-toggle")).toBeVisible();
    await expect(mockedPage.getByTestId("profile-button")).toBeVisible();
  });

  test("undo/redo and share are hidden on the welcome screen", async ({
    mockedPage,
  }) => {
    // No trip open at "/" → undo/redo + share must not be present
    await expect(mockedPage.getByTestId("undo-button")).toHaveCount(0);
    await expect(mockedPage.getByTestId("redo-button")).toHaveCount(0);
    await expect(mockedPage.getByTestId("share-button")).toHaveCount(0);
  });

  test("undo/redo and share are visible on a trip detail route", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    // submitUrl navigates to /trips/{id}
    await expect(mockedPage).toHaveURL(/\/trips\//);

    await expect(mockedPage.getByTestId("undo-button")).toBeVisible();
    await expect(mockedPage.getByTestId("redo-button")).toBeVisible();
    await expect(mockedPage.getByTestId("share-button")).toBeVisible();
  });

  test("share button opens the share modal on a trip", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());

    await mockedPage.getByTestId("share-button").click();
    // The share API GET is unmocked here, so the modal settles on the
    // "create share link" state — its presence proves the modal is open.
    await expect(
      mockedPage.getByTestId("share-create-link-button"),
    ).toBeVisible({ timeout: 5000 });
  });

  test("language pills toggle the active locale", async ({ mockedPage }) => {
    const fr = mockedPage.getByTestId("locale-switch-fr");
    const en = mockedPage.getByTestId("locale-switch-en");

    await expect(fr).toHaveAttribute("aria-pressed", "true");
    await expect(en).toHaveAttribute("aria-pressed", "false");

    // Clicking EN triggers the locale change (router.refresh under the hood)
    await en.click();
    await mockedPage.waitForLoadState("networkidle");
    await expect(mockedPage.getByTestId("locale-switch-en")).toHaveAttribute(
      "aria-pressed",
      "true",
    );
  });

  test("theme toggle cycles through light, dark and auto", async ({
    mockedPage,
  }) => {
    const toggle = mockedPage.getByTestId("theme-toggle");
    await expect(toggle).toBeVisible();

    const initial = await toggle.getAttribute("data-theme-state");
    await toggle.click();
    await expect(toggle).not.toHaveAttribute("data-theme-state", initial ?? "");

    // Three clicks return to the starting state (light → dark → system → …)
    await toggle.click();
    await toggle.click();
    await expect(toggle).toHaveAttribute("data-theme-state", initial ?? "");
  });

  test("profile button links to /account/settings", async ({ mockedPage }) => {
    const profile = mockedPage.getByTestId("profile-button");
    await expect(profile).toHaveAttribute("href", "/account/settings");
    await profile.click();
    await mockedPage.waitForURL(/\/account\/settings/);
    await expect(mockedPage.getByTestId("account-settings-page")).toBeVisible();
  });

  test("help button opens the unified help modal with two tabs", async ({
    mockedPage,
  }) => {
    await mockedPage.getByTestId("help-button").click();
    await expect(mockedPage.getByTestId("help-modal")).toBeVisible();

    // Shortcuts tab active by default
    await expect(mockedPage.getByTestId("help-tab-shortcuts")).toHaveAttribute(
      "aria-selected",
      "true",
    );
    await expect(
      mockedPage.getByTestId("help-tab-shortcuts-panel"),
    ).toBeVisible();

    // Switch to FAQ tab
    await mockedPage.getByTestId("help-tab-faq").click();
    await expect(mockedPage.getByTestId("help-tab-faq")).toHaveAttribute(
      "aria-selected",
      "true",
    );
    await expect(mockedPage.getByTestId("help-tab-faq-panel")).toBeVisible();
  });
});
