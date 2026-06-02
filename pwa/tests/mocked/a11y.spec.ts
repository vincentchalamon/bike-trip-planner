import { test, expect } from "../fixtures/base.fixture";
import { getCriticalA11yViolations } from "../fixtures/a11y";

/**
 * Sprint 35 a11y smoke — proves the axe-core harness is wired into the fixture
 * chain and runs against rendered screens. It deliberately does NOT gate on
 * violations: the app is not yet a11y-audited. Enforcing "0 critical/serious"
 * via `expectNoCriticalA11yViolations()` and filing findings is Sprint 35.2.
 * Here we only assert the scan executes and surface the count as an annotation.
 */
test.describe("a11y smoke", () => {
  test("axe harness runs on the welcome screen", async ({ mockedPage }) => {
    await expect(mockedPage.getByTestId("card-selection")).toBeVisible();
    const blocking = await getCriticalA11yViolations(mockedPage);
    test
      .info()
      .annotations.push({
        type: "a11y",
        description: `welcome: ${blocking.length} critical/serious violations (audited in Sprint 35.2)`,
      });
  });

  test("axe harness runs on a loaded trip", async ({
    mockedPage,
    createFullTrip,
  }) => {
    await createFullTrip();
    await expect(mockedPage.getByTestId("stage-card-3")).toBeVisible();
    const blocking = await getCriticalA11yViolations(mockedPage);
    test
      .info()
      .annotations.push({
        type: "a11y",
        description: `trip: ${blocking.length} critical/serious violations (audited in Sprint 35.2)`,
      });
  });
});
