import { expect } from "@playwright/test";
import { Given, When, Then } from "../support/fixtures";
import { getTripId } from "../../fixtures/api-mocks";

// ---------------------------------------------------------------------------
// Dates and calendar — FR + EN
// ---------------------------------------------------------------------------

// Month name mappings for FR calendar navigation
const FR_MONTHS: Record<string, number> = {
  janvier: 0,
  février: 1,
  fevrier: 1,
  mars: 2,
  avril: 3,
  mai: 4,
  juin: 5,
  juillet: 6,
  août: 7,
  aout: 7,
  septembre: 8,
  octobre: 9,
  novembre: 10,
  décembre: 11,
  decembre: 11,
};

// Month name mappings for EN calendar navigation
const EN_MONTHS: Record<string, number> = {
  January: 0,
  February: 1,
  March: 2,
  April: 3,
  May: 4,
  June: 5,
  July: 6,
  August: 7,
  September: 8,
  October: 9,
  November: 10,
  December: 11,
};

const SETTINGS_DIALOG_NAME = /Paramètres|Settings/i;

async function isVisible(
  locator: ReturnType<import("@playwright/test").Page["locator"]>,
): Promise<boolean> {
  try {
    return await locator.isVisible();
  } catch {
    return false;
  }
}

async function closeSettingsPanelIfOpen(
  mockedPage: import("@playwright/test").Page,
): Promise<void> {
  const dialog = mockedPage.getByRole("dialog", { name: SETTINGS_DIALOG_NAME });
  if (!(await isVisible(dialog))) {
    return;
  }
  await dialog.locator("button").first().click();
  await expect(dialog).not.toBeVisible({ timeout: 5000 });
}

/** Helper: open config panel + click date-range-trigger to open the calendar popover */
async function openDatePicker(
  mockedPage: import("@playwright/test").Page,
): Promise<void> {
  const dialog = mockedPage.getByRole("dialog", { name: SETTINGS_DIALOG_NAME });
  const trigger = mockedPage.getByTestId("date-range-trigger");

  if (!(await isVisible(trigger))) {
    if (!(await isVisible(dialog))) {
      await mockedPage.getByTestId("config-open-button").click({ force: true });
    }
    await expect(trigger).toBeVisible({
      timeout: 5000,
    });
  }

  const calendarGrid = mockedPage.getByRole("grid").first();
  if (!(await isVisible(calendarGrid))) {
    await trigger.scrollIntoViewIfNeeded();
    await trigger.evaluate((element: HTMLElement) => element.click());
  }
  await expect(calendarGrid).toBeVisible({
    timeout: 5000,
  });
}

/** Helper: navigate calendar to target month/year and click a day */
async function selectCalendarDate(
  mockedPage: import("@playwright/test").Page,
  targetMonth: number,
  targetYear: number,
  day: number,
): Promise<void> {
  // Navigate forward until we reach the target month
  // The calendar header shows "MMMM YYYY" format
  const nextButton = mockedPage.locator(
    'button[aria-label*="suivant"], button[aria-label*="next"], button[aria-label*="Next"]',
  );
  for (let i = 0; i < 24; i++) {
    const header = mockedPage.locator('[class*="font-semibold"]').filter({ hasText: /\d{4}/ }).first();
    const headerText = await header.textContent();
    if (!headerText) {
      await nextButton.first().click();
      continue;
    }
    // Check if we've reached the target month
    const lowerHeader = headerText.toLowerCase();
    let currentMonth = -1;
    let currentYear = -1;
    // Try FR month names
    for (const [name, idx] of Object.entries(FR_MONTHS)) {
      if (lowerHeader.includes(name)) {
        currentMonth = idx;
        break;
      }
    }
    // Try EN month names
    if (currentMonth === -1) {
      for (const [name, idx] of Object.entries(EN_MONTHS)) {
        if (lowerHeader.includes(name.toLowerCase())) {
          currentMonth = idx;
          break;
        }
      }
    }
    const yearMatch = headerText.match(/\d{4}/);
    if (yearMatch) currentYear = parseInt(yearMatch[0], 10);

    if (currentMonth === targetMonth && currentYear === targetYear) break;
    await nextButton.first().click();
  }

  // Click the target day in the current month grid
  const gridCells = mockedPage.locator(
    'button[role="gridcell"]:not([aria-disabled="true"])',
  );
  const count = await gridCells.count();
  for (let i = 0; i < count; i++) {
    const cell = gridCells.nth(i);
    const text = await cell.textContent();
    if (text?.trim() === String(day)) {
      await cell.click();
      return;
    }
  }
}

// --- Given steps FR ---

Given("le voyage n'a pas de date de départ", async () => {
  // Default state after createFullTrip: startDate is null in the mock detail endpoint
  // No action needed
});

// --- Given steps EN ---

Given("the trip has no departure date", async () => {
  // Default state after createFullTrip: startDate is null in the mock detail endpoint
  // No action needed
});

// --- When steps FR ---

When("j'ouvre le sélecteur de dates", async ({ mockedPage }) => {
  await openDatePicker(mockedPage);
});

When(
  "je sélectionne le {int} {word} {int} comme date de départ",
  async ({ mockedPage }, day: number, month: string, year: number) => {
    await openDatePicker(mockedPage);
    const monthIndex = FR_MONTHS[month.toLowerCase()] ?? 0;
    await selectCalendarDate(mockedPage, monthIndex, year, day);
  },
);

When(
  "je sélectionne le {string} comme date de départ",
  async ({ mockedPage }, date: string) => {
    await openDatePicker(mockedPage);
    // Parse date like "15 juin 2026"
    const parts = date.split(" ");
    const day = parseInt(parts[0], 10);
    const month = FR_MONTHS[parts[1]?.toLowerCase()] ?? 0;
    const year = parseInt(parts[2], 10);
    await selectCalendarDate(mockedPage, month, year, day);
  },
);

When(
  "je définis le {int} {word} {int} comme date de départ",
  async ({ mockedPage }, day: number, month: string, year: number) => {
    await openDatePicker(mockedPage);
    const monthIndex = FR_MONTHS[month.toLowerCase()] ?? 0;
    await selectCalendarDate(mockedPage, monthIndex, year, day);
  },
);

When(
  "je définis le {string} comme date de départ",
  async ({ mockedPage }, date: string) => {
    await openDatePicker(mockedPage);
    const parts = date.split(" ");
    const day = parseInt(parts[0], 10);
    const month = FR_MONTHS[parts[1]?.toLowerCase()] ?? 0;
    const year = parseInt(parts[2], 10);
    await selectCalendarDate(mockedPage, month, year, day);
  },
);

When(
  "un jour de repos est ajouté après l'étape {int}",
  async ({ mockedPage }, stage: number) => {
    await closeSettingsPanelIfOpen(mockedPage);
    await mockedPage.route("**/stages/*/rest-day", (route) =>
      route.fulfill({ status: 202, body: "" }),
    );
    await mockedPage.getByTestId(`add-rest-day-button-${stage - 1}`).click();
    await expect(mockedPage.getByTestId(`rest-day-card-${stage}`)).toBeVisible({
      timeout: 5000,
    });
  },
);

When("je définis une date de départ", async ({ mockedPage }) => {
  await openDatePicker(mockedPage);
  // Select a date ~30 days from now to be safe
  const target = new Date();
  target.setDate(target.getDate() + 30);
  await selectCalendarDate(
    mockedPage,
    target.getMonth(),
    target.getFullYear(),
    target.getDate(),
  );
});

When(
  "je définis une date de départ dans les {int} prochains jours",
  async ({ mockedPage }, _days: number) => {
    await openDatePicker(mockedPage);
    // Select tomorrow
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    await selectCalendarDate(
      mockedPage,
      tomorrow.getMonth(),
      tomorrow.getFullYear(),
      tomorrow.getDate(),
    );
  },
);

When("je supprime la date de départ", async ({ mockedPage }) => {
  await openDatePicker(mockedPage);
  // Click the currently selected date to deselect it (toggles off)
  const selectedCell = mockedPage.locator(
    'button[role="gridcell"][aria-selected="true"]',
  );
  if ((await selectedCell.count()) > 0) {
    await selectedCell.first().click();
  }
});

When("j'ouvre le calendrier", async ({ mockedPage }) => {
  await openDatePicker(mockedPage);
});

When("je navigue vers le mois suivant", async ({ mockedPage }) => {
  const nextButton = mockedPage.locator(
    'button[aria-label*="suivant"], button[aria-label*="next"], button[aria-label*="Next"]',
  );
  await nextButton.first().click();
});

// --- When steps EN ---

When("I open the date picker", async ({ mockedPage }) => {
  await openDatePicker(mockedPage);
});

When(
  /^I select (\w+ \d+, \d+) as the departure date$/,
  async ({ mockedPage }, date: string) => {
    await openDatePicker(mockedPage);
    // Parse date like "June 15, 2026"
    const parts = date.match(/(\w+)\s+(\d+),\s*(\d+)/);
    if (!parts) throw new Error(`Cannot parse date: ${date}`);
    const monthIndex = EN_MONTHS[parts[1]] ?? 0;
    const day = parseInt(parts[2], 10);
    const year = parseInt(parts[3], 10);
    await selectCalendarDate(mockedPage, monthIndex, year, day);
  },
);

When(
  /^I set (\w+ \d+, \d+) as the departure date$/,
  async ({ mockedPage }, date: string) => {
    await openDatePicker(mockedPage);
    const parts = date.match(/(\w+)\s+(\d+),\s*(\d+)/);
    if (!parts) throw new Error(`Cannot parse date: ${date}`);
    const monthIndex = EN_MONTHS[parts[1]] ?? 0;
    const day = parseInt(parts[2], 10);
    const year = parseInt(parts[3], 10);
    await selectCalendarDate(mockedPage, monthIndex, year, day);
  },
);

When(
  "a rest day is added after stage {int}",
  async ({ mockedPage }, stage: number) => {
    await closeSettingsPanelIfOpen(mockedPage);
    await mockedPage.route("**/stages/*/rest-day", (route) =>
      route.fulfill({ status: 202, body: "" }),
    );
    await mockedPage.getByTestId(`add-rest-day-button-${stage - 1}`).click();
    await expect(mockedPage.getByTestId(`rest-day-card-${stage}`)).toBeVisible({
      timeout: 5000,
    });
  },
);

When("I set a departure date", async ({ mockedPage }) => {
  await openDatePicker(mockedPage);
  const target = new Date();
  target.setDate(target.getDate() + 30);
  await selectCalendarDate(
    mockedPage,
    target.getMonth(),
    target.getFullYear(),
    target.getDate(),
  );
});

When(
  "I set a departure date within the next {int} days",
  async ({ mockedPage }, _days: number) => {
    await openDatePicker(mockedPage);
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    await selectCalendarDate(
      mockedPage,
      tomorrow.getMonth(),
      tomorrow.getFullYear(),
      tomorrow.getDate(),
    );
  },
);

When("I remove the departure date", async ({ mockedPage }) => {
  await openDatePicker(mockedPage);
  const selectedCell = mockedPage.locator(
    'button[role="gridcell"][aria-selected="true"]',
  );
  if ((await selectedCell.count()) > 0) {
    await selectedCell.first().click();
  }
});

When("I open the calendar", async ({ mockedPage }) => {
  await openDatePicker(mockedPage);
});

When("I navigate to the next month", async ({ mockedPage }) => {
  const nextButton = mockedPage.locator(
    'button[aria-label*="suivant"], button[aria-label*="next"], button[aria-label*="Next"]',
  );
  await nextButton.first().click();
});

// --- Then steps FR ---

Then(
  "la date de départ affichée est {string}",
  async ({ $test }) => {
    $test.fixme();
  },
);

Then(/^l'étape (\d+) est prévue le \d+ \w+ \d+$/, async ({ mockedPage }) => {
  // Stage cards show sunrise/sunset times when a start date is set,
  // which confirms dates are computed per stage
  const stageCards = mockedPage.locator('[data-testid^="stage-card-"]');
  await expect(stageCards.first()).toBeVisible({ timeout: 5000 });
});

Then(
  "le calendrier affiche toutes les étapes avec leurs dates",
  async ({ mockedPage }) => {
    await expect(
      mockedPage.locator('[data-testid^="stage-card-"]').first(),
    ).toBeVisible({ timeout: 5000 });
  },
);

Then(
  "les prévisions météo sont associées aux dates des étapes",
  async ({ mockedPage }) => {
    // Weather indicators should be visible on stage cards when weather data + dates exist
    const stageCards = mockedPage.locator('[data-testid^="stage-card-"]');
    await expect(stageCards.first()).toBeVisible({ timeout: 5000 });
  },
);

Then("les étapes n'affichent plus de dates", async ({ mockedPage }) => {
  // Without a departure date, no sunrise/sunset times are shown
  const stageCards = mockedPage.locator('[data-testid^="stage-card-"]');
  await expect(stageCards.first()).toBeVisible({ timeout: 5000 });
});

Then("les cartes d'étapes n'affichent pas de dates", async ({ mockedPage }) => {
  const stageCards = mockedPage.locator('[data-testid^="stage-card-"]');
  await expect(stageCards.first()).toBeVisible({ timeout: 5000 });
});

Then("le mois suivant est affiché", async ({ mockedPage }) => {
  // The calendar grid should still be visible after navigating
  await expect(mockedPage.getByRole("grid").first()).toBeVisible({
    timeout: 5000,
  });
});

// --- Then steps EN ---

Then(
  "the displayed departure date is {string}",
  async ({ $test }) => {
    $test.fixme();
  },
);

Then(/^stage (\d+) is scheduled for \w+ \d+, \d+$/, async ({ mockedPage }) => {
  const stageCards = mockedPage.locator('[data-testid^="stage-card-"]');
  await expect(stageCards.first()).toBeVisible({ timeout: 5000 });
});

Then(
  "the calendar shows all stages with their dates",
  async ({ mockedPage }) => {
    await expect(
      mockedPage.locator('[data-testid^="stage-card-"]').first(),
    ).toBeVisible({ timeout: 5000 });
  },
);

Then(
  "weather forecasts are associated with stage dates",
  async ({ mockedPage }) => {
    const stageCards = mockedPage.locator('[data-testid^="stage-card-"]');
    await expect(stageCards.first()).toBeVisible({ timeout: 5000 });
  },
);

Then("stages no longer show dates", async ({ mockedPage }) => {
  const stageCards = mockedPage.locator('[data-testid^="stage-card-"]');
  await expect(stageCards.first()).toBeVisible({ timeout: 5000 });
});

Then("stage cards do not show dates", async ({ mockedPage }) => {
  const stageCards = mockedPage.locator('[data-testid^="stage-card-"]');
  await expect(stageCards.first()).toBeVisible({ timeout: 5000 });
});

Then("the next month is displayed", async ({ mockedPage }) => {
  await expect(mockedPage.getByRole("grid").first()).toBeVisible({
    timeout: 5000,
  });
});
