import { test, expect } from "../fixtures/base.fixture";
import { expectNoCriticalA11yViolations } from "../fixtures/a11y";

/**
 * Sprint 35 a11y smoke — light coverage proving the axe-core helper and the
 * runtime monitor are wired into the fixture chain. The flag is turned ON so
 * `mockedPage` attaches the runtime monitor and asserts no console error /
 * HTTP 5xx after the test. Exhaustive per-screen audits land in Sprint 35.2.
 */
test.describe("a11y smoke", () => {
  test.use({ mockOptions: { assertNoRuntimeErrors: true } });

  test("welcome screen has no critical/serious a11y violations", async ({
    mockedPage,
  }) => {
    await expect(mockedPage.getByTestId("card-selection")).toBeVisible();
    await expectNoCriticalA11yViolations(mockedPage);
  });

  test("loaded trip has no critical/serious a11y violations", async ({
    mockedPage,
    createFullTrip,
  }) => {
    await createFullTrip();
    await expect(mockedPage.getByTestId("stage-card-3")).toBeVisible();
    await expectNoCriticalA11yViolations(mockedPage);
  });
});
