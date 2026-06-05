import {
  test as visualTest,
  expect as visualExpect,
  maskRegions,
  MAP_SCREEN_SKIP_REASON,
  shouldSkipMapScreen,
} from "./support/visual.fixture";
import { getTripId } from "../fixtures/api-mocks";

/**
 * Visual-regression baselines for the **states / overlays** set of the recette
 * manifest (`docs/recette/03-manifeste-elements.md` — §Modales, §États UI):
 *
 *  - Share modal, Config panel, Help modal (Shortcuts + FAQ tabs) on a loaded
 *    roadbook;
 *  - an error toast (transient UI);
 *  - the 404 page and a realistic error surface (`TripNotFound`).
 *
 * Run / regenerate with `make visual-test` / `make visual-update` against a
 * running prod stack. Non-deterministic regions (maps, canvases, dates, AI
 * text) are masked via `maskRegions`.
 *
 * Note on the App Router error boundary (`error.tsx`, design `PageErrorDesktop`,
 * "Erreur serveur · 500"): triggering it on a real navigation requires a
 * test-only route shipped into the bundle, which is intentionally avoided here
 * (see `tests/mocked/error-pages.spec.ts`). We therefore baseline the
 * user-reachable error surface instead: the trip 404 (`TripNotFound`) rendered
 * by `/trips/[id]` on a failed detail fetch. The `error.tsx`/`global-error.tsx`
 * polish stays a human side-by-side check in the manual recette.
 */
visualTest.describe("visual baselines (states & overlays)", () => {
  // --- Modals / overlays on a loaded roadbook -------------------------------

  visualTest("modal-share", async ({ visualPage, gotoRoadbook }, testInfo) => {
    visualTest.skip(shouldSkipMapScreen(testInfo), MAP_SCREEN_SKIP_REASON);
    await gotoRoadbook();
    // No active share yet → the modal shows the "create link" CTA.
    await visualPage.route(
      `**/trips/${getTripId()}/share`,
      (route, request) => {
        if (request.method() === "GET") {
          return route.fulfill({ status: 404, body: "" });
        }
        return route.fallback();
      },
    );
    await visualPage.getByTestId("share-button").click();
    await visualExpect(
      visualPage
        .getByTestId("share-create-link-button")
        .or(visualPage.getByTestId("share-link-text")),
    ).toBeVisible({ timeout: 10000 });
    await visualPage.waitForTimeout(300);
    await visualExpect(visualPage).toHaveScreenshot("modal-share.png", {
      fullPage: true,
      mask: maskRegions(visualPage),
    });
  });

  visualTest("panel-config", async ({ visualPage, gotoRoadbook }, testInfo) => {
    visualTest.skip(shouldSkipMapScreen(testInfo), MAP_SCREEN_SKIP_REASON);
    await gotoRoadbook();
    await visualPage.getByTestId("config-open-button").click();
    await visualExpect(
      visualPage.getByRole("dialog", { name: /Param[eè]tres|Settings/i }),
    ).toBeVisible({ timeout: 10000 });
    await visualPage.waitForTimeout(300);
    await visualExpect(visualPage).toHaveScreenshot("panel-config.png", {
      fullPage: true,
      mask: maskRegions(visualPage),
    });
  });

  visualTest(
    "modal-help-shortcuts",
    async ({ visualPage, gotoRoadbook }, testInfo) => {
      visualTest.skip(shouldSkipMapScreen(testInfo), MAP_SCREEN_SKIP_REASON);
      await gotoRoadbook();
      await visualPage.getByTestId("help-button").click();
      await visualExpect(visualPage.getByTestId("help-modal")).toBeVisible({
        timeout: 10000,
      });
      // Shortcuts tab is the default.
      await visualExpect(
        visualPage.getByTestId("help-tab-shortcuts-panel"),
      ).toBeVisible();
      await visualPage.waitForTimeout(200);
      await visualExpect(visualPage).toHaveScreenshot(
        "modal-help-shortcuts.png",
        {
          fullPage: true,
          mask: maskRegions(visualPage),
        },
      );
    },
  );

  // Manifest "Modale FAQ" == the FAQ tab of the unified help modal (the app has
  // no standalone FAQ modal; the help modal hosts the same accordion as /faq).
  visualTest(
    "modal-help-faq",
    async ({ visualPage, gotoRoadbook }, testInfo) => {
      visualTest.skip(shouldSkipMapScreen(testInfo), MAP_SCREEN_SKIP_REASON);
      await gotoRoadbook();
      await visualPage.getByTestId("help-button").click();
      await visualExpect(visualPage.getByTestId("help-modal")).toBeVisible({
        timeout: 10000,
      });
      await visualPage.getByTestId("help-tab-faq").click();
      await visualExpect(
        visualPage.getByTestId("help-tab-faq-panel"),
      ).toBeVisible();
      await visualPage.waitForTimeout(200);
      await visualExpect(visualPage).toHaveScreenshot("modal-help-faq.png", {
        fullPage: true,
        mask: maskRegions(visualPage),
      });
    },
  );

  // --- Transient UI: toast ---------------------------------------------------

  // Deterministic error toast: the account-settings export endpoint is mocked
  // to fail, so clicking "export" surfaces `toast.error(...)` (top-right).
  visualTest("toast-error", async ({ visualPage }) => {
    await visualPage.route("**/users/me/export", (route) =>
      route.fulfill({ status: 500, body: "" }),
    );
    await visualPage.goto("/account/settings");
    await visualExpect(
      visualPage.getByTestId("export-data-button"),
    ).toBeVisible({ timeout: 10000 });
    await visualPage.getByTestId("export-data-button").click();
    // Assert the toast ITEM, not the `[data-sonner-toaster]` <ol> container —
    // sonner keeps the container in the DOM but reports it hidden until a toast
    // mounts, so a container visibility check is unreliable. The error toast is
    // sticky enough to capture; pin it open by extending its lifetime is not
    // needed since we screenshot immediately after it appears.
    await visualExpect(
      visualPage.locator("[data-sonner-toast]").first(),
    ).toBeVisible({ timeout: 10000 });
    await visualExpect(visualPage).toHaveScreenshot("toast-error.png", {
      fullPage: true,
      mask: maskRegions(visualPage),
    });
  });

  // --- System screens --------------------------------------------------------

  visualTest("not-found", async ({ visualPage }) => {
    // AuthGuard would redirect a non-public path to /login when unauthenticated;
    // the mock chain establishes an authenticated session so the 404 renders.
    await visualPage.goto("/route-inexistante");
    await visualExpect(visualPage.getByTestId("not-found-page")).toBeVisible({
      timeout: 10000,
    });
    await visualExpect(visualPage).toHaveScreenshot("not-found.png", {
      fullPage: true,
      mask: maskRegions(visualPage),
    });
  });

  visualTest("trip-error", async ({ visualPage }) => {
    // A failed detail fetch on /trips/[id] renders the `TripNotFound` surface —
    // the user-reachable stand-in for the App Router error boundary.
    await visualPage.route("**/trips/*/detail", (route, request) => {
      if (request.method() !== "GET") return route.fallback();
      return route.fulfill({ status: 500, body: "" });
    });
    await visualPage.goto(`/trips/${getTripId()}`);
    await visualExpect(
      visualPage.getByTestId("trip-not-found-page"),
    ).toBeVisible({ timeout: 10000 });
    await visualExpect(visualPage).toHaveScreenshot("trip-error.png", {
      fullPage: true,
      mask: maskRegions(visualPage),
    });
  });
});
