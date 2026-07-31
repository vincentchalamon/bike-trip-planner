import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  calendarAlertsEvent,
  tripCompleteEvent,
} from "../fixtures/mock-data";

test.describe("Calendar alerts over Mercure", () => {
  // Guards the frontend/backend contract reworked in #864: the payload moved from
  // `nudges` to `alerts` and the severity is now read from `type` instead of being
  // hardcoded to "nudge" on the client.
  test("renders calendar alerts under the severity the server sent", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      calendarAlertsEvent(),
      tripCompleteEvent(),
    ]);

    // Stage 1 carries the nudge-level holiday alert.
    const stage1 = mockedPage.getByTestId("stage-card-1");
    await stage1.getByTestId("alert-group-toggle-nudge").click();
    await expect(stage1).toContainText("jour ferie");

    // Stage 2 carries a warning-level alert. Expanding the *warning* group is the
    // assertion: with the previously hardcoded `type: "nudge"` this toggle would not
    // exist at all and the click would fail.
    const stage2 = mockedPage.getByTestId("stage-card-2");
    await stage2.getByTestId("alert-group-toggle-warning").click();
    await expect(stage2).toContainText("dimanche");
  });
});

test.describe("Date range picker in ConfigPanel", () => {
  test("shows date range picker when config panel opens", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    // Open config panel
    await mockedPage.getByTestId("config-open-button").click();
    // Date range trigger should be visible inside the panel
    await expect(mockedPage.getByTestId("date-range-trigger")).toBeVisible();
  });

  test("opens calendar popover on date trigger click", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    // Open config panel
    await mockedPage.getByTestId("config-open-button").click();
    // Click date range trigger
    await mockedPage.getByTestId("date-range-trigger").click();
    // Calendar grid should appear in the popover
    await expect(mockedPage.getByRole("grid").first()).toBeVisible();
  });

  test("clicking dates chip in summary opens config panel at dates section", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    // Click dates chip in summary
    await mockedPage.getByTestId("summary-dates").click();
    // Config panel should open
    const configPanel = mockedPage.locator(
      '[role="dialog"][aria-modal="true"]',
    );
    await expect(configPanel).toBeInViewport();
    // Date range trigger should be visible
    await expect(mockedPage.getByTestId("date-range-trigger")).toBeVisible();
  });

  test("clicking profile chip in summary opens config panel at pacing section", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    // Click profile chip in summary
    await mockedPage.getByTestId("summary-profile").click();
    // Config panel should open
    const configPanel = mockedPage.locator(
      '[role="dialog"][aria-modal="true"]',
    );
    await expect(configPanel).toBeInViewport();
    // Pacing section heading should be visible
    await expect(
      mockedPage.getByRole("heading", { name: /profil cyclo/i }),
    ).toBeVisible();
  });
});
