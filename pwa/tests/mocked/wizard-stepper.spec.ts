import { test as base, expect, type Page } from "@playwright/test";
import { mockAllApis } from "../fixtures/api-mocks";
import { injectSseEvent } from "../fixtures/sse-helpers";
import { routeParsedEvent, stagesComputedEvent } from "../fixtures/mock-data";
import type { MercureEvent } from "../../src/lib/mercure/types";

/**
 * Fixtures for the WizardStepper tests.
 *
 * The WizardStepper (`data-testid="wizard-stepper"`) is only rendered on the
 * `/trips/new` route. The internal `<Stepper />` (test IDs `stepper-step-*`)
 * is a separate component covered by `stepper-flow.spec.ts`.
 *
 * Because `handleMagicLink` calls `router.push('/trips/{id}')` after POST, a
 * real URL submission navigates away from `/trips/new` before the WizardStepper
 * can show the analysis state. Tests for advanced states therefore drive the
 * Zustand store directly via the `__test_set_processing` and
 * `__test_set_analysis_started` event hooks exposed by TripPlanner for E2E use.
 */
interface WizardFixtures {
  wizardPage: Page;
  injectWizardEvent: (event: MercureEvent) => Promise<void>;
  /**
   * Simulate `isProcessing=true` via the TripPlanner test hook. This triggers
   * the same stepper transition that a real URL submit would (preparation +
   * preview → analysis) without navigating away from /trips/new.
   */
  simulateProcessing: () => Promise<void>;
}

const test = base.extend<WizardFixtures>({
  wizardPage: async ({ page }, use) => {
    await mockAllApis(page);
    await page.goto("/trips/new");
    await page.waitForLoadState("networkidle");
    // Expand the Link card so the magic-link-input is immediately available.
    const linkCard = page.getByTestId("card-link");
    if (await linkCard.isVisible().catch(() => false)) {
      const expanded = await linkCard.getAttribute("data-expanded");
      if (expanded !== "true") await linkCard.click();
    }
    await use(page);
  },

  injectWizardEvent: async ({ wizardPage }, use) => {
    await use((event: MercureEvent) => injectSseEvent(wizardPage, event));
  },

  simulateProcessing: async ({ wizardPage }, use) => {
    await use(async () => {
      await wizardPage.evaluate(() => {
        window.dispatchEvent(
          new CustomEvent("__test_set_processing", { detail: true }),
        );
      });
      // Wait for the stepper to reflect the analysis step
      await expect(wizardPage.getByTestId("wizard-stepper")).toHaveAttribute(
        "data-current-step",
        "analysis",
        { timeout: 5000 },
      );
    });
  },
});

test.describe("WizardStepper — initial state", () => {
  test("wizard stepper is visible on /trips/new", async ({ wizardPage }) => {
    await expect(wizardPage.getByTestId("wizard-stepper")).toBeVisible();
  });

  test("all 4 steps are rendered initially", async ({ wizardPage }) => {
    await expect(
      wizardPage.getByTestId("wizard-stepper-step-preparation"),
    ).toBeVisible();
    await expect(
      wizardPage.getByTestId("wizard-stepper-step-preview"),
    ).toBeVisible();
    await expect(
      wizardPage.getByTestId("wizard-stepper-step-analysis"),
    ).toBeVisible();
    await expect(
      wizardPage.getByTestId("wizard-stepper-step-my_trip"),
    ).toBeVisible();
  });

  test("first step 'preparation' is active initially", async ({
    wizardPage,
  }) => {
    const stepEl = wizardPage.getByTestId("wizard-stepper-step-preparation");
    await expect(stepEl).toHaveAttribute("aria-current", "step");
  });

  test("initial step is reflected in data-current-step attribute", async ({
    wizardPage,
  }) => {
    await expect(wizardPage.getByTestId("wizard-stepper")).toHaveAttribute(
      "data-current-step",
      "preparation",
    );
  });

  test("inactive steps do not have aria-current initially", async ({
    wizardPage,
  }) => {
    await expect(
      wizardPage.getByTestId("wizard-stepper-step-preview"),
    ).not.toHaveAttribute("aria-current");
    await expect(
      wizardPage.getByTestId("wizard-stepper-step-analysis"),
    ).not.toHaveAttribute("aria-current");
    await expect(
      wizardPage.getByTestId("wizard-stepper-step-my_trip"),
    ).not.toHaveAttribute("aria-current");
  });
});

test.describe("WizardStepper — 'analysis' is never a button", () => {
  test("analysis step is a div (not a button) on initial load", async ({
    wizardPage,
  }) => {
    const analysisEl = wizardPage.getByTestId("wizard-stepper-step-analysis");
    await expect(analysisEl).toBeVisible();
    const tagName = await analysisEl.evaluate((el) =>
      el.tagName.toLowerCase(),
    );
    expect(tagName).toBe("div");
  });

  test("analysis step is a div (not a button) when it is the active step", async ({
    wizardPage,
    simulateProcessing,
  }) => {
    await simulateProcessing();
    // analysis is now the active step — it must never become a button
    const analysisEl = wizardPage.getByTestId("wizard-stepper-step-analysis");
    await expect(analysisEl).toHaveAttribute("aria-current", "step");
    const tagName = await analysisEl.evaluate((el) =>
      el.tagName.toLowerCase(),
    );
    expect(tagName).toBe("div");
  });
});

test.describe("WizardStepper — completed steps become buttons (before my_trip)", () => {
  test("preparation step becomes a button once completed while on analysis", async ({
    wizardPage,
    simulateProcessing,
  }) => {
    await simulateProcessing();
    // isProcessing=true → preparation is completed, analysis is active, not my_trip
    // → preparation should be rendered as a <button> (clickable back-navigation)
    const preparationEl = wizardPage.getByTestId(
      "wizard-stepper-step-preparation",
    );
    await expect(preparationEl).toBeVisible();
    const tagName = await preparationEl.evaluate((el) =>
      el.tagName.toLowerCase(),
    );
    expect(tagName).toBe("button");
  });

  test("preview step becomes a button once completed while on analysis", async ({
    wizardPage,
    simulateProcessing,
  }) => {
    await simulateProcessing();
    // isProcessing=true also completes "preview" per TripPlanner useEffect
    const previewEl = wizardPage.getByTestId("wizard-stepper-step-preview");
    await expect(previewEl).toBeVisible();
    const tagName = await previewEl.evaluate((el) => el.tagName.toLowerCase());
    expect(tagName).toBe("button");
  });
});

test.describe("WizardStepper — non-clickable steps remain divs", () => {
  test("future steps are never buttons", async ({ wizardPage }) => {
    // On initial load (preparation active), preview/analysis/my_trip are future
    // steps and must never be rendered as buttons.
    for (const stepId of ["preview", "analysis", "my_trip"] as const) {
      const el = wizardPage.getByTestId(`wizard-stepper-step-${stepId}`);
      await expect(el).toBeVisible();
      const tagName = await el.evaluate((e) => e.tagName.toLowerCase());
      expect(tagName, `future step '${stepId}' should be a div`).toBe("div");
    }
  });

  test("the active step is never a button", async ({ wizardPage }) => {
    // Active step is never clickable (cannot navigate to the step you are on)
    const preparationEl = wizardPage.getByTestId(
      "wizard-stepper-step-preparation",
    );
    await expect(preparationEl).toHaveAttribute("aria-current", "step");
    const tagName = await preparationEl.evaluate((el) =>
      el.tagName.toLowerCase(),
    );
    expect(tagName).toBe("div");
  });

  test("analysis and my_trip are always divs (never buttons) during analysis", async ({
    wizardPage,
    simulateProcessing,
  }) => {
    await simulateProcessing();
    // analysis: active step → always div
    const analysisEl = wizardPage.getByTestId("wizard-stepper-step-analysis");
    const analysisTag = await analysisEl.evaluate((el) =>
      el.tagName.toLowerCase(),
    );
    expect(analysisTag).toBe("div");

    // my_trip: future step → always div
    const myTripEl = wizardPage.getByTestId("wizard-stepper-step-my_trip");
    const myTripTag = await myTripEl.evaluate((el) => el.tagName.toLowerCase());
    expect(myTripTag).toBe("div");
  });
});

test.describe("WizardStepper — URL forward-jump blocked", () => {
  test("navigating to ?step=4 when on step 1 stays on step 1", async ({
    page,
  }) => {
    await mockAllApis(page);
    await page.goto("/trips/new?step=4");
    await page.waitForLoadState("networkidle");

    // The URL should be rewritten to step=1 (preparation) since the wizard
    // cannot forward-jump to a step the user hasn't reached yet.
    // waitForURL is more reliable than expect(page).toHaveURL() here because
    // the redirect is driven by a React useEffect (async after hydration).
    await page.waitForURL(/step=1/, { timeout: 5000 });

    // Preparation step should be the active one
    await expect(page.getByTestId("wizard-stepper")).toHaveAttribute(
      "data-current-step",
      "preparation",
    );
  });

  test("navigating to ?step=3 (analysis) when on step 1 stays on step 1", async ({
    page,
  }) => {
    await mockAllApis(page);
    await page.goto("/trips/new?step=3");
    await page.waitForLoadState("networkidle");

    // Forward jump to analysis (system step) is also blocked
    await page.waitForURL(/step=1/, { timeout: 5000 });
    await expect(page.getByTestId("wizard-stepper")).toHaveAttribute(
      "data-current-step",
      "preparation",
    );
  });

  test("navigating to ?step=2 when on step 1 stays on step 1", async ({
    page,
  }) => {
    await mockAllApis(page);
    await page.goto("/trips/new?step=2");
    await page.waitForLoadState("networkidle");

    // Forward jump to preview is blocked (user hasn't submitted a route yet)
    await page.waitForURL(/step=1/, { timeout: 5000 });
    await expect(page.getByTestId("wizard-stepper")).toHaveAttribute(
      "data-current-step",
      "preparation",
    );
  });
});

test.describe("WizardStepper — back navigation clears the trip", () => {
  test("clicking preparation button from analysis rewinds to preparation", async ({
    wizardPage,
    simulateProcessing,
  }) => {
    await simulateProcessing();

    // preparation is now a button (completed, before analysis, not my_trip)
    const preparationBtn = wizardPage.getByTestId(
      "wizard-stepper-step-preparation",
    );
    const tagName = await preparationBtn.evaluate((el) =>
      el.tagName.toLowerCase(),
    );
    expect(tagName).toBe("button");

    await preparationBtn.click();

    // The wizard should rewind back to preparation
    await expect(wizardPage.getByTestId("wizard-stepper")).toHaveAttribute(
      "data-current-step",
      "preparation",
      { timeout: 5000 },
    );
  });

  test("back navigation to preparation restores initial UI (card selection visible)", async ({
    wizardPage,
    simulateProcessing,
  }) => {
    await simulateProcessing();

    // Navigate back to preparation via the stepper button
    await wizardPage
      .getByTestId("wizard-stepper-step-preparation")
      .click();

    // The card selection should be visible again (trip was cleared)
    await expect(wizardPage.getByTestId("wizard-stepper")).toHaveAttribute(
      "data-current-step",
      "preparation",
      { timeout: 5000 },
    );
  });
});

test.describe("WizardStepper — step transitions", () => {
  test("stepper advances to analysis when processing starts", async ({
    wizardPage,
    simulateProcessing,
  }) => {
    await simulateProcessing();
    await expect(wizardPage.getByTestId("wizard-stepper")).toHaveAttribute(
      "data-current-step",
      "analysis",
    );
    const analysisEl = wizardPage.getByTestId("wizard-stepper-step-analysis");
    await expect(analysisEl).toHaveAttribute("aria-current", "step");
  });

  test("preparation step is no longer active during analysis", async ({
    wizardPage,
    simulateProcessing,
  }) => {
    await simulateProcessing();
    await expect(
      wizardPage.getByTestId("wizard-stepper-step-preparation"),
    ).not.toHaveAttribute("aria-current");
  });
});

test.describe("WizardStepper — accessibility", () => {
  test("wizard stepper has role=navigation", async ({ wizardPage }) => {
    const stepper = wizardPage.getByTestId("wizard-stepper");
    await expect(stepper).toHaveAttribute("role", "navigation");
  });

  test("active step has aria-current=step", async ({ wizardPage }) => {
    const preparationStep = wizardPage.getByTestId(
      "wizard-stepper-step-preparation",
    );
    await expect(preparationStep).toHaveAttribute("aria-current", "step");
  });

  test("each step has data-step-number attribute", async ({ wizardPage }) => {
    await expect(
      wizardPage.getByTestId("wizard-stepper-step-preparation"),
    ).toHaveAttribute("data-step-number", "1");
    await expect(
      wizardPage.getByTestId("wizard-stepper-step-preview"),
    ).toHaveAttribute("data-step-number", "2");
    await expect(
      wizardPage.getByTestId("wizard-stepper-step-analysis"),
    ).toHaveAttribute("data-step-number", "3");
    await expect(
      wizardPage.getByTestId("wizard-stepper-step-my_trip"),
    ).toHaveAttribute("data-step-number", "4");
  });
});

test.describe("WizardStepper — responsive mobile", () => {
  test("mobile compact label is visible on small viewport", async ({
    wizardPage,
  }) => {
    await wizardPage.setViewportSize({ width: 375, height: 812 });
    const mobileLabel = wizardPage.getByTestId("wizard-stepper-mobile-label");
    await expect(mobileLabel).toBeVisible();
  });

  test("mobile label shows correct step info for preparation", async ({
    wizardPage,
  }) => {
    await wizardPage.setViewportSize({ width: 375, height: 812 });
    const mobileLabel = wizardPage.getByTestId("wizard-stepper-mobile-label");
    // At preparation step: label should mention "1/4"
    await expect(mobileLabel).toContainText("1/4");
  });

  test("mobile label updates when step advances to analysis", async ({
    wizardPage,
    simulateProcessing,
  }) => {
    await wizardPage.setViewportSize({ width: 375, height: 812 });
    await simulateProcessing();
    const mobileLabel = wizardPage.getByTestId("wizard-stepper-mobile-label");
    // After moving to analysis: "3/4"
    await expect(mobileLabel).toContainText("3/4", { timeout: 5000 });
  });
});

test.describe("WizardStepper — WizardStepper vs internal Stepper", () => {
  test("wizard-stepper test IDs are distinct from internal stepper test IDs", async ({
    wizardPage,
  }) => {
    // WizardStepper uses wizard-stepper-step-* test IDs
    await expect(
      wizardPage.getByTestId("wizard-stepper-step-preparation"),
    ).toBeVisible();

    // The internal Stepper (stepper-step-*) is suppressed via hideStepper=true
    // on /trips/new, so those test IDs should NOT be present.
    await expect(
      wizardPage.getByTestId("stepper-step-preparation"),
    ).not.toBeAttached();
  });

  test("SSE events are injectable on /trips/new (WizardStepper route)", async ({
    wizardPage,
    injectWizardEvent,
    simulateProcessing,
  }) => {
    await simulateProcessing();
    // Injecting SSE events should not cause errors on /trips/new
    await injectWizardEvent(routeParsedEvent());
    await injectWizardEvent(stagesComputedEvent());

    // Stepper remains on analysis (isProcessing=true, not cleared by these events)
    await expect(wizardPage.getByTestId("wizard-stepper")).toHaveAttribute(
      "data-current-step",
      "analysis",
    );
  });
});
