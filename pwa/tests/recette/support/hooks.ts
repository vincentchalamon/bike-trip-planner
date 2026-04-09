import { createBdd } from "playwright-bdd";
import { test } from "./fixtures";

const { Before, After } = createBdd(test);

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
