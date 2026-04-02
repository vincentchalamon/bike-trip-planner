import { Before, After, BeforeAll, AfterAll } from "playwright-bdd";

BeforeAll(async function () {
  // Global setup — runs once before all BDD scenarios
});

AfterAll(async function () {
  // Global teardown — runs once after all BDD scenarios
});

Before(async function ({ page }) {
  // Per-scenario setup
  await page.context().clearCookies();
});

After(async function ({ page }, testInfo) {
  // Per-scenario teardown: screenshot on failure
  if (testInfo.status !== "passed") {
    await page.screenshot({
      path: `recette-report/screenshots/${testInfo.title.replace(/[^a-z0-9]/gi, "_")}.png`,
      fullPage: true,
    });
  }
});
