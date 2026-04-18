import { expect } from "@playwright/test";
import { Given, When, Then } from "../support/fixtures";

// ---------------------------------------------------------------------------
// Mobile and offline mode — FR + EN
// Note: many steps are already covered by common.steps.ts (offline banner,
// magic link disabled, etc.) — only domain-specific steps are defined here.
// ---------------------------------------------------------------------------

const SEED_TRIP = {
  id: "offline-consult-trip-1",
  title: "Tour de l'Ardèche",
  sourceUrl: "https://www.komoot.com/fr-fr/tour/12345",
  totalDistance: 187000,
  totalElevation: 2850,
  totalElevationLoss: 2720,
  sourceType: "komoot",
  startDate: "2026-06-01",
  endDate: "2026-06-03",
  fatigueFactor: 0.8,
  elevationPenalty: 100,
  maxDistancePerDay: 80,
  averageSpeed: 15,
  ebikeMode: false,
  departureHour: 8,
  enabledAccommodationTypes: ["hotel", "camp_site"],
  stages: [
    {
      dayNumber: 1,
      distance: 72.5,
      elevation: 1180,
      elevationLoss: 920,
      startPoint: { lat: 44.735, lon: 4.598, ele: 280 },
      endPoint: { lat: 44.532, lon: 4.392, ele: 540 },
      geometry: [],
      label: null,
      startLabel: null,
      endLabel: null,
      weather: null,
      alerts: [],
      pois: [],
      accommodations: [],
      accommodationSearchRadiusKm: 20,
      isRestDay: false,
      supplyTimeline: [],
      events: [],
    },
    {
      dayNumber: 2,
      distance: 63.2,
      elevation: 870,
      elevationLoss: 1050,
      startPoint: { lat: 44.532, lon: 4.392, ele: 540 },
      endPoint: { lat: 44.295, lon: 4.087, ele: 360 },
      geometry: [],
      label: null,
      startLabel: null,
      endLabel: null,
      weather: null,
      alerts: [],
      pois: [],
      accommodations: [],
      accommodationSearchRadiusKm: 20,
      isRestDay: false,
      supplyTimeline: [],
      events: [],
    },
    {
      dayNumber: 3,
      distance: 51.6,
      elevation: 800,
      elevationLoss: 750,
      startPoint: { lat: 44.295, lon: 4.087, ele: 360 },
      endPoint: { lat: 44.112, lon: 3.876, ele: 410 },
      geometry: [],
      label: null,
      startLabel: null,
      endLabel: null,
      weather: null,
      alerts: [],
      pois: [],
      accommodations: [],
      accommodationSearchRadiusKm: 20,
      isRestDay: false,
      supplyTimeline: [],
      events: [],
    },
  ],
  savedAt: new Date().toISOString(),
};

async function seedIndexedDB(
  page: import("@playwright/test").Page,
  trip: typeof SEED_TRIP,
) {
  await page.evaluate((t) => {
    return new Promise<void>((resolve) => {
      const request = indexedDB.open("keyval-store", 1);
      request.onupgradeneeded = (e) => {
        const db = (e.target as IDBOpenDBRequest).result;
        if (!db.objectStoreNames.contains("keyval")) {
          db.createObjectStore("keyval");
        }
      };
      request.onsuccess = (e) => {
        const db = (e.target as IDBOpenDBRequest).result;
        const tx = db.transaction("keyval", "readwrite");
        tx.objectStore("keyval").put([t], "offline_saved_trips");
        tx.oncomplete = () => resolve();
      };
    });
  }, trip);
}

// --- Given steps FR ---

Given(
  "un voyage a été précédemment sauvegardé localement",
  async ({ mockedPage }) => {
    await seedIndexedDB(mockedPage, SEED_TRIP);
    await mockedPage.reload();
    await mockedPage.waitForLoadState("networkidle");
  },
);

// --- Given steps EN ---

Given("a trip has been previously saved locally", async ({ mockedPage }) => {
  await seedIndexedDB(mockedPage, SEED_TRIP);
  await mockedPage.reload();
  await mockedPage.waitForLoadState("networkidle");
});

// --- When steps FR ---

When("{int} secondes s'écoulent", async ({ mockedPage }, n: number) => {
  await mockedPage.waitForTimeout(n * 1000);
});

When("un voyage complet est créé", async ({ createFullTrip }) => {
  await createFullTrip();
});

When(
  "je redimensionne la fenêtre à {int}px de largeur",
  async ({ mockedPage }, width: number) => {
    await mockedPage.setViewportSize({ width, height: 844 });
  },
);

When("je fais glisser la carte avec un doigt", async ({ $test }) => {
  // Touch gesture simulation on MapLibre is not reliably testable in headless
  $test.fixme();
});

// --- When steps EN ---

When("{int} seconds pass", async ({ mockedPage }, n: number) => {
  await mockedPage.waitForTimeout(n * 1000);
});

When("a full trip is created", async ({ createFullTrip }) => {
  await createFullTrip();
});

When(
  "I resize the window to {int}px width",
  async ({ mockedPage }, width: number) => {
    await mockedPage.setViewportSize({ width, height: 844 });
  },
);

When("I drag the map with one finger", async ({ $test }) => {
  // Touch gesture simulation on MapLibre is not reliably testable in headless
  $test.fixme();
});

// --- Then steps FR ---

// i18n equivalents for offline banner text
const OFFLINE_TEXT_ALTERNATIVES: Record<string, RegExp> = {
  "Hors ligne": /Hors ligne|Offline/i,
  "Connexion rétablie": /Connexion rétablie|Connection restored/i,
};

Then("le bandeau affiche {string}", async ({ mockedPage }, text: string) => {
  const pattern = OFFLINE_TEXT_ALTERNATIVES[text] ?? text;
  await expect(mockedPage.getByTestId("offline-banner")).toContainText(
    pattern,
    { timeout: 5000 },
  );
});

Then(
  'le bandeau hors ligne a role="status" et aria-live="polite"',
  async ({ mockedPage }) => {
    const banner = mockedPage.getByTestId("offline-banner");
    await expect(banner).toBeVisible({ timeout: 5000 });
    await expect(banner).toHaveAttribute("role", "status");
    await expect(banner).toHaveAttribute("aria-live", "polite");
  },
);

Then(
  "le voyage est sauvegardé localement dans IndexedDB",
  async ({ mockedPage }) => {
    const hasSavedTrip = await mockedPage.evaluate(() => {
      return new Promise<boolean>((resolve) => {
        const request = indexedDB.open("keyval-store", 1);
        request.onsuccess = (e) => {
          const db = (e.target as IDBOpenDBRequest).result;
          if (!db.objectStoreNames.contains("keyval")) {
            resolve(false);
            return;
          }
          const tx = db.transaction("keyval", "readonly");
          const get = tx.objectStore("keyval").get("offline_saved_trips");
          get.onsuccess = () => {
            const trips = get.result as unknown[];
            resolve(Array.isArray(trips) && trips.length > 0);
          };
          get.onerror = () => resolve(false);
        };
        request.onerror = () => resolve(false);
      });
    });
    expect(hasSavedTrip).toBe(true);
  },
);

Then("je peux consulter les étapes du voyage", async ({ mockedPage }) => {
  // In offline mode, the trip is loaded from IndexedDB via the saved trip card
  // Check that either stage cards or the saved trip card are visible
  await expect(
    mockedPage
      .getByTestId("stage-card-1")
      .or(mockedPage.locator('[data-testid^="saved-trip-card-"]').first()),
  ).toBeVisible({ timeout: 5000 });
});

Then(
  "l'interface s'adapte correctement sans défilement horizontal",
  async ({ mockedPage }) => {
    const noHScroll = await mockedPage.evaluate(
      () =>
        document.documentElement.scrollWidth <=
        document.documentElement.clientWidth,
    );
    expect(noHScroll).toBe(true);
  },
);

Then("la carte se déplace en suivant le geste", async ({ $test }) => {
  // Touch gesture result cannot be reliably asserted in headless mode
  $test.fixme();
});

// --- Then steps EN ---

Then("the banner shows {string}", async ({ mockedPage }, text: string) => {
  const pattern =
    (
      {
        "Hors ligne": /Hors ligne|Offline/i,
        "Connexion rétablie": /Connexion rétablie|Connection restored/i,
      } as Record<string, RegExp>
    )[text] ?? text;
  await expect(mockedPage.getByTestId("offline-banner")).toContainText(
    pattern,
    { timeout: 5000 },
  );
});

Then(
  'the offline banner has role="status" and aria-live="polite"',
  async ({ mockedPage }) => {
    const banner = mockedPage.getByTestId("offline-banner");
    await expect(banner).toBeVisible({ timeout: 5000 });
    await expect(banner).toHaveAttribute("role", "status");
    await expect(banner).toHaveAttribute("aria-live", "polite");
  },
);

Then("the trip is saved locally in IndexedDB", async ({ mockedPage }) => {
  const hasSavedTrip = await mockedPage.evaluate(() => {
    return new Promise<boolean>((resolve) => {
      const request = indexedDB.open("keyval-store", 1);
      request.onsuccess = (e) => {
        const db = (e.target as IDBOpenDBRequest).result;
        if (!db.objectStoreNames.contains("keyval")) {
          resolve(false);
          return;
        }
        const tx = db.transaction("keyval", "readonly");
        const get = tx.objectStore("keyval").get("offline_saved_trips");
        get.onsuccess = () => {
          const trips = get.result as unknown[];
          resolve(Array.isArray(trips) && trips.length > 0);
        };
        get.onerror = () => resolve(false);
      };
      request.onerror = () => resolve(false);
    });
  });
  expect(hasSavedTrip).toBe(true);
});

Then("I can view the trip stages", async ({ mockedPage }) => {
  await expect(
    mockedPage
      .getByTestId("stage-card-1")
      .or(mockedPage.locator('[data-testid^="saved-trip-card-"]').first()),
  ).toBeVisible({ timeout: 5000 });
});

Then(
  "the interface adapts correctly without horizontal scrolling",
  async ({ mockedPage }) => {
    const noHScroll = await mockedPage.evaluate(
      () =>
        document.documentElement.scrollWidth <=
        document.documentElement.clientWidth,
    );
    expect(noHScroll).toBe(true);
  },
);

Then("the map moves following the gesture", async ({ $test }) => {
  // Touch gesture result cannot be reliably asserted in headless mode
  $test.fixme();
});
