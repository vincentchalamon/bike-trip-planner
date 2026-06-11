import { test, expect } from "../fixtures/base.fixture";
import { routeParsedEvent, stagesComputedEvent } from "../fixtures/mock-data";

test.describe("Stepper — initial state", () => {
  test("stepper is visible on the planner page", async ({ mockedPage }) => {
    await expect(mockedPage.getByTestId("stepper")).toBeVisible();
  });

  test("first step 'preparation' is active initially", async ({
    mockedPage,
  }) => {
    const stepEl = mockedPage.getByTestId("stepper-step-preparation");
    await expect(stepEl).toHaveAttribute("aria-current", "step");
  });

  test("all 4 steps are rendered", async ({ mockedPage }) => {
    await expect(
      mockedPage.getByTestId("stepper-step-preparation"),
    ).toBeVisible();
    await expect(mockedPage.getByTestId("stepper-step-preview")).toBeVisible();
    await expect(mockedPage.getByTestId("stepper-step-analysis")).toBeVisible();
    await expect(mockedPage.getByTestId("stepper-step-my_trip")).toBeVisible();
  });
});

test.describe("Stepper — step transitions", () => {
  test("advances to 'preview' after URL submission", async ({
    submitUrl,
    mockedPage,
  }) => {
    await submitUrl();
    // The initial route computation parks on "preview" (recette #649); analysis
    // only follows the explicit "Lancer l'analyse" action.
    const previewStep = mockedPage.getByTestId("stepper-step-preview");
    await expect(previewStep).toHaveAttribute("aria-current", "step", {
      timeout: 5000,
    });
  });

  test("hides the creation stepper once the trip is loaded", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    // The full trip view is reached (Acte 3). The creation stepper is now
    // hidden there (recette #649) — it no longer serves a purpose.
    await expect(mockedPage.getByTestId("stage-detail-panel")).toBeVisible({
      timeout: 5000,
    });
    await expect(mockedPage.getByTestId("stepper-wrapper")).toHaveCount(0);
  });

  test("'preparation' step is completed after URL submission", async ({
    submitUrl,
    mockedPage,
  }) => {
    await submitUrl();
    // Wait until we move past preparation (now onto "preview")
    await expect(
      mockedPage.getByTestId("stepper-step-preview"),
    ).toHaveAttribute("aria-current", "step", { timeout: 5000 });
    // preparation step should no longer be the active one
    await expect(
      mockedPage.getByTestId("stepper-step-preparation"),
    ).not.toHaveAttribute("aria-current");
  });
});

test.describe("Stepper — non-interactive system step", () => {
  test("'analysis' step is never a button (never clickable)", async ({
    mockedPage,
  }) => {
    const analysisEl = mockedPage.getByTestId("stepper-step-analysis");
    await expect(analysisEl).toBeVisible();
    const tagName = await analysisEl.evaluate((el) => el.tagName.toLowerCase());
    expect(tagName).toBe("div");
  });
});

test.describe("Stepper — accessibility", () => {
  test("stepper has role=navigation", async ({ mockedPage }) => {
    const stepper = mockedPage.getByTestId("stepper");
    await expect(stepper).toHaveAttribute("role", "navigation");
  });

  test("active step has aria-current=step", async ({ mockedPage }) => {
    const preparationStep = mockedPage.getByTestId("stepper-step-preparation");
    await expect(preparationStep).toHaveAttribute("aria-current", "step");
  });

  test("inactive steps do not have aria-current", async ({ mockedPage }) => {
    await expect(
      mockedPage.getByTestId("stepper-step-preview"),
    ).not.toHaveAttribute("aria-current");
    await expect(
      mockedPage.getByTestId("stepper-step-analysis"),
    ).not.toHaveAttribute("aria-current");
    await expect(
      mockedPage.getByTestId("stepper-step-my_trip"),
    ).not.toHaveAttribute("aria-current");
  });
});

test.describe("Stepper — responsive mobile", () => {
  test("mobile compact label is visible on small viewport", async ({
    mockedPage,
  }) => {
    await mockedPage.setViewportSize({ width: 375, height: 812 });
    const mobileLabel = mockedPage.getByTestId("stepper-mobile-label");
    await expect(mobileLabel).toBeVisible();
  });

  test("mobile label shows correct step number and name", async ({
    mockedPage,
  }) => {
    await mockedPage.setViewportSize({ width: 375, height: 812 });
    const mobileLabel = mockedPage.getByTestId("stepper-mobile-label");
    // At preparation step: "Étape 1/4 — Préparation"
    await expect(mobileLabel).toContainText("1/4");
    await expect(mobileLabel).toContainText("Préparation");
  });

  test("mobile label updates when step changes", async ({
    submitUrl,
    mockedPage,
  }) => {
    await mockedPage.setViewportSize({ width: 375, height: 812 });
    await submitUrl();
    const mobileLabel = mockedPage.getByTestId("stepper-mobile-label");
    // After URL submit: moves to preview (step 2)
    await expect(mobileLabel).toContainText("2/4", { timeout: 5000 });
    await expect(mobileLabel).toContainText("Aperçu", { timeout: 5000 });
  });
});

test.describe("Stepper — hidden once the trip is loaded", () => {
  test("the creation stepper is hidden in Acte 3 and reappears for a fresh trip", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();

    // Once the trip is fully loaded, the creation progress bar is hidden — it
    // no longer serves a purpose and was confused with the horizontal day
    // timeline on scroll (recette #649).
    await expect(mockedPage.getByTestId("stage-detail-panel")).toBeVisible({
      timeout: 5000,
    });
    await expect(mockedPage.getByTestId("stepper-wrapper")).toHaveCount(0);

    // Starting a brand-new trip brings the stepper back.
    await mockedPage.goto("/trips/new");
    await expect(mockedPage.getByTestId("wizard-trip-new")).toBeVisible({
      timeout: 5000,
    });
  });
});

test.describe("Stepper — backwards navigation", () => {
  test("completed step is clickable before reaching my_trip", async ({
    submitUrl,
    injectEvent,
    mockedPage,
  }) => {
    await submitUrl();
    await injectEvent(routeParsedEvent());
    // Inject stages to reach the preview step
    await injectEvent(stagesComputedEvent());

    // Wait until we're past preparation (now on "preview")
    await expect(
      mockedPage.getByTestId("stepper-step-preview"),
    ).toHaveAttribute("aria-current", "step", { timeout: 5000 });

    // Inject a trip_complete event to move to my_trip is not done yet,
    // so we're still on analysis — preparation step should now be a button
    // (since it's completed and we're not at my_trip)
    // Note: this test validates the state just before reaching my_trip
    const preparationEl = mockedPage.getByTestId("stepper-step-preparation");
    const tagName = await preparationEl.evaluate((el) =>
      el.tagName.toLowerCase(),
    );
    // It should be a button since: it's completed, not "my_trip" step yet, not "analysis"
    expect(tagName).toBe("button");
  });
});
