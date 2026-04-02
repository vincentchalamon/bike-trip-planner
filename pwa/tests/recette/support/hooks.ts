import { createBdd } from "playwright-bdd";
import { test } from "./fixtures";

const { Before, After, BeforeAll, AfterAll } = createBdd(test);

BeforeAll(async () => {
  // Global setup — runs once before all BDD scenarios
});

AfterAll(async () => {
  // Global teardown — runs once after all BDD scenarios
});

Before(async ({ page }) => {
  await page.context().clearCookies();
});

After(async ({ page, $testInfo }) => {
  if ($testInfo.status !== "passed") {
    await page.screenshot({
      path: `recette-report/screenshots/${$testInfo.title.replace(/[^a-z0-9]/gi, "_")}.png`,
      fullPage: true,
    });
  }
});
