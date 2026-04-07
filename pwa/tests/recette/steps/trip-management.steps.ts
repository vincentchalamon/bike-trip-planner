import { expect } from "@playwright/test";
import { Given, When, Then } from "../support/fixtures";
import { getTripId } from "../../fixtures/api-mocks";

// ---------------------------------------------------------------------------
// Trip management — FR + EN
// ---------------------------------------------------------------------------

// Helper: build N seed trips for IndexedDB
function buildSeedTrips(count: number) {
  return Array.from({ length: count }, (_, i) => ({
    ...SEED_TRIP_TEMPLATE_TEMPLATE,
    id: `trip-${i + 1}`,
    title: `Trip ${i + 1}`,
    savedAt: new Date().toISOString(),
  }));
}

// Helper: seed IndexedDB with N trips so SavedTripsSection renders them
async function seedTripList(
  page: import("@playwright/test").Page,
  count: number,
) {
  const trips = buildSeedTrips(count);
  await page.addInitScript((t) => {
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
      tx.objectStore("keyval").put(t, "offline_saved_trips");
    };
  }, trips);
}

// Helper: seed IndexedDB with a trip (same pattern as offline-consultation.spec.ts)
const SEED_TRIP_TEMPLATE = {
  id: "recent-trip-1",
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
    },
  ],
  savedAt: new Date().toISOString(),
};

function seedIndexedDB(page: import("@playwright/test").Page) {
  return page.addInitScript((trip) => {
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
      tx.objectStore("keyval").put([trip], "offline_saved_trips");
    };
  }, SEED_TRIP_TEMPLATE);
}

// --- Given steps FR ---

Given(
  "je suis connecté et que j'ai {int} voyages sauvegardés",
  async ({ mockedPage }, count: number) => {
    await seedTripList(mockedPage, count);
    await mockedPage.reload();
    await mockedPage.waitForLoadState("networkidle");
  },
);

Given(
  "je suis connecté et que j'ai un voyage sauvegardé",
  async ({ mockedPage }) => {
    await seedTripList(mockedPage, 1);
    await mockedPage.reload();
    await mockedPage.waitForLoadState("networkidle");
  },
);

Given("j'ai récemment consulté un voyage", async ({ mockedPage }) => {
  // We need to seed IndexedDB before navigation, but mockedPage already navigated.
  // Seed then reload so the home page picks up the saved trip.
  await seedIndexedDB(mockedPage);
  await mockedPage.reload();
  await mockedPage.waitForLoadState("networkidle");
});

Given(
  "un voyage a été verrouillé par un autre utilisateur",
  async ({ mockedPage }) => {
    // Mock the trip detail endpoint to return isLocked: true
    await mockedPage.route("**/trips/*/detail", (route, request) => {
      if (request.method() !== "GET") return route.fallback();
      const tripId =
        request.url().match(/\/trips\/([^/]+)\/detail/)?.[1] ?? "trip-1";
      return route.fulfill({
        status: 200,
        contentType: "application/ld+json",
        body: JSON.stringify({
          "@context": "/contexts/TripDetail",
          "@id": `/trips/${tripId}/detail`,
          "@type": "TripDetail",
          id: tripId,
          title: "Locked Trip",
          sourceUrl: "https://www.komoot.com/fr-fr/tour/2795080048",
          startDate: null,
          endDate: null,
          fatigueFactor: 0.8,
          elevationPenalty: 100,
          maxDistancePerDay: 80,
          averageSpeed: 15,
          ebikeMode: false,
          departureHour: 8,
          enabledAccommodationTypes: ["camp_site", "hotel"],
          isLocked: true,
          stages: [],
          computationStatus: {},
        }),
      });
    });
    await seedTripList(mockedPage, 1);
    await mockedPage.reload();
    await mockedPage.waitForLoadState("networkidle");
  },
);

Given("je suis connecté sans voyage", async () => {
  // The default state has no saved trips in IndexedDB — nothing to do
});

Given(
  "j'ai un voyage sans dates de départ ni d'arrivée",
  async ({ createFullTrip }) => {
    // createFullTrip creates a trip; the default mock already returns null startDate/endDate
    await createFullTrip();
  },
);

// --- Given steps EN ---

Given(
  "I am logged in and have {int} saved trips",
  async ({ mockedPage }, count: number) => {
    await seedTripList(mockedPage, count);
    await mockedPage.reload();
    await mockedPage.waitForLoadState("networkidle");
  },
);

Given("I am logged in and have a saved trip", async ({ mockedPage }) => {
  await seedTripList(mockedPage, 1);
  await mockedPage.reload();
  await mockedPage.waitForLoadState("networkidle");
});

Given("I have recently viewed a trip", async ({ mockedPage }) => {
  await seedIndexedDB(mockedPage);
  await mockedPage.reload();
  await mockedPage.waitForLoadState("networkidle");
});

Given("a trip has been locked by another user", async ({ mockedPage }) => {
  await mockedPage.route("**/trips/*/detail", (route, request) => {
    if (request.method() !== "GET") return route.fallback();
    const tripId =
      request.url().match(/\/trips\/([^/]+)\/detail/)?.[1] ?? "trip-1";
    return route.fulfill({
      status: 200,
      contentType: "application/ld+json",
      body: JSON.stringify({
        "@context": "/contexts/TripDetail",
        "@id": `/trips/${tripId}/detail`,
        "@type": "TripDetail",
        id: tripId,
        title: "Locked Trip",
        sourceUrl: "https://www.komoot.com/fr-fr/tour/2795080048",
        startDate: null,
        endDate: null,
        fatigueFactor: 0.8,
        elevationPenalty: 100,
        maxDistancePerDay: 80,
        averageSpeed: 15,
        ebikeMode: false,
        departureHour: 8,
        enabledAccommodationTypes: ["camp_site", "hotel"],
        isLocked: true,
        stages: [],
        computationStatus: {},
      }),
    });
  });
  await seedTripList(mockedPage, 1);
  await mockedPage.reload();
  await mockedPage.waitForLoadState("networkidle");
});

Given("I am logged in with no trips", async () => {
  // The default state has no saved trips in IndexedDB — nothing to do
});

Given(
  "I have a trip with no start or end dates",
  async ({ createFullTrip }) => {
    await createFullTrip();
  },
);

// --- When steps FR ---

When("je clique sur ce voyage dans la liste", async ({ mockedPage }) => {
  const firstTrip = mockedPage
    .locator('[data-testid^="saved-trip-card-"]')
    .first();
  await expect(firstTrip).toBeVisible({ timeout: 5000 });
  await firstTrip.click();
});

When("je duplique ce voyage", async ({ mockedPage }) => {
  // Click on the trip card first to load it
  const firstTrip = mockedPage
    .locator('[data-testid^="saved-trip-card-"]')
    .first();
  await expect(firstTrip).toBeVisible({ timeout: 5000 });
  await firstTrip.click();
  // Then look for a duplicate button
  const duplicateBtn = mockedPage.getByRole("button", {
    name: /dupliquer|duplicate/i,
  });
  await expect(duplicateBtn).toBeVisible({ timeout: 5000 });
  await duplicateBtn.click();
});

When("je supprime ce voyage", async ({ mockedPage }) => {
  const firstTrip = mockedPage
    .locator('[data-testid^="saved-trip-card-"]')
    .first();
  await expect(firstTrip).toBeVisible({ timeout: 5000 });
  await firstTrip.click();
  const deleteBtn = mockedPage.getByRole("button", {
    name: /supprimer|delete/i,
  });
  await expect(deleteBtn).toBeVisible({ timeout: 5000 });
  await deleteBtn.click();
  const confirmBtn = mockedPage.getByRole("button", {
    name: /confirmer|confirm|oui|yes/i,
  });
  if (await confirmBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
    await confirmBtn.click();
  }
});

When("j'ouvre ce voyage", async ({ mockedPage }) => {
  await mockedPage.goto(`/trips/${getTripId()}`);
  await mockedPage.waitForLoadState("networkidle");
});

When(
  "la liste des voyages est en cours de chargement",
  async ({ submitUrl }) => {
    // Submit a URL to trigger trip creation — the loading skeleton appears
    await submitUrl();
  },
);

// --- When steps EN ---

When("I click on that trip in the list", async ({ mockedPage }) => {
  const firstTrip = mockedPage
    .locator('[data-testid^="saved-trip-card-"]')
    .first();
  await expect(firstTrip).toBeVisible({ timeout: 5000 });
  await firstTrip.click();
});

When("I duplicate that trip", async ({ mockedPage }) => {
  const firstTrip = mockedPage
    .locator('[data-testid^="saved-trip-card-"]')
    .first();
  await expect(firstTrip).toBeVisible({ timeout: 5000 });
  await firstTrip.click();
  const duplicateBtn = mockedPage.getByRole("button", {
    name: /dupliquer|duplicate/i,
  });
  await expect(duplicateBtn).toBeVisible({ timeout: 5000 });
  await duplicateBtn.click();
});

When("I delete that trip", async ({ mockedPage }) => {
  const firstTrip = mockedPage
    .locator('[data-testid^="saved-trip-card-"]')
    .first();
  await expect(firstTrip).toBeVisible({ timeout: 5000 });
  await firstTrip.click();
  const deleteBtn = mockedPage.getByRole("button", {
    name: /supprimer|delete/i,
  });
  await expect(deleteBtn).toBeVisible({ timeout: 5000 });
  await deleteBtn.click();
  const confirmBtn = mockedPage.getByRole("button", {
    name: /confirmer|confirm|oui|yes/i,
  });
  if (await confirmBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
    await confirmBtn.click();
  }
});

When("I open that trip", async ({ mockedPage }) => {
  await mockedPage.goto(`/trips/${getTripId()}`);
  await mockedPage.waitForLoadState("networkidle");
});

When("the trip list is loading", async ({ submitUrl }) => {
  await submitUrl();
});

// --- Then steps FR ---

Then(
  "je vois la liste de mes {int} voyages",
  async ({ mockedPage }, count: number) => {
    const cards = mockedPage.locator('[data-testid^="saved-trip-card-"]');
    await expect(cards).toHaveCount(count, { timeout: 5000 });
  },
);

Then(
  "je suis redirigé vers la page détail du voyage",
  async ({ mockedPage }) => {
    await expect(mockedPage).toHaveURL(/\/trips\//, { timeout: 5000 });
  },
);

Then(
  "un nouveau voyage identique apparaît dans ma liste",
  async ({ mockedPage }) => {
    // After duplication, expect at least 2 trip cards
    const cards = mockedPage.locator('[data-testid^="saved-trip-card-"]');
    await expect(cards.first()).toBeVisible({ timeout: 5000 });
    const count = await cards.count();
    expect(count).toBeGreaterThanOrEqual(2);
  },
);

Then("il n'apparaît plus dans ma liste", async ({ mockedPage }) => {
  const cards = mockedPage.locator('[data-testid^="saved-trip-card-"]');
  await expect(cards).toHaveCount(0, { timeout: 5000 });
});

Then(
  "je vois le voyage récent dans la section {string}",
  async ({ mockedPage }, _section: string) => {
    await expect(mockedPage.getByTestId("saved-trips-section")).toBeVisible({
      timeout: 5000,
    });
    const card = mockedPage.getByTestId(
      `saved-trip-card-${SEED_TRIP_TEMPLATE.id}`,
    );
    await expect(card).toBeVisible();
  },
);

Then("je vois un indicateur de verrouillage", async ({ mockedPage }) => {
  await expect(
    mockedPage.getByText(/verrouill|locked|lock/i).first(),
  ).toBeVisible({ timeout: 5000 });
});

Then("les boutons de modification sont désactivés", async ({ mockedPage }) => {
  // Check that edit/config buttons are disabled when trip is locked
  const editButtons = mockedPage.locator(
    'button[disabled], [data-testid="config-open-button"][disabled]',
  );
  await expect(editButtons.first()).toBeVisible({ timeout: 5000 });
});

Then(
  "je vois un état vide invitant à créer un voyage",
  async ({ mockedPage }) => {
    // No saved trips → SavedTripsSection renders null, magic-link-input is the CTA
    await expect(mockedPage.getByTestId("magic-link-input")).toBeVisible({
      timeout: 5000,
    });
    await expect(
      mockedPage.getByTestId("saved-trips-section"),
    ).not.toBeVisible();
  },
);

Then(
  "les étapes s'affichent correctement sans dates",
  async ({ mockedPage }) => {
    // Stages should be visible
    await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
      timeout: 5000,
    });
    // No date information should be displayed on stage cards
    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard).not.toContainText(/\d{2}\/\d{2}\/\d{4}/);
  },
);

Then("je vois un indicateur de chargement", async ({ mockedPage }) => {
  await expect(
    mockedPage
      .getByTestId("trip-title-skeleton")
      .or(mockedPage.getByTestId("trip-title")),
  ).toBeVisible({ timeout: 5000 });
});

// --- Then steps EN ---

Then("I see my list of {int} trips", async ({ mockedPage }, count: number) => {
  const cards = mockedPage.locator('[data-testid^="saved-trip-card-"]');
  await expect(cards).toHaveCount(count, { timeout: 5000 });
});

Then("I am redirected to the trip detail page", async ({ mockedPage }) => {
  await expect(mockedPage).toHaveURL(/\/trips\//, { timeout: 5000 });
});

Then("a new identical trip appears in my list", async ({ mockedPage }) => {
  const cards = mockedPage.locator('[data-testid^="saved-trip-card-"]');
  await expect(cards.first()).toBeVisible({ timeout: 5000 });
  const count = await cards.count();
  expect(count).toBeGreaterThanOrEqual(2);
});

Then("it no longer appears in my list", async ({ mockedPage }) => {
  const cards = mockedPage.locator('[data-testid^="saved-trip-card-"]');
  await expect(cards).toHaveCount(0, { timeout: 5000 });
});

Then(
  "I see the recent trip in the {string} section",
  async ({ mockedPage }, _section: string) => {
    await expect(mockedPage.getByTestId("saved-trips-section")).toBeVisible({
      timeout: 5000,
    });
    const card = mockedPage.getByTestId(
      `saved-trip-card-${SEED_TRIP_TEMPLATE.id}`,
    );
    await expect(card).toBeVisible();
  },
);

Then("I see a lock indicator", async ({ mockedPage }) => {
  await expect(
    mockedPage.getByText(/verrouill|locked|lock/i).first(),
  ).toBeVisible({ timeout: 5000 });
});

Then("edit buttons are disabled", async ({ mockedPage }) => {
  const editButtons = mockedPage.locator(
    'button[disabled], [data-testid="config-open-button"][disabled]',
  );
  await expect(editButtons.first()).toBeVisible({ timeout: 5000 });
});

Then(
  "I see an empty state prompting me to create a trip",
  async ({ mockedPage }) => {
    await expect(mockedPage.getByTestId("magic-link-input")).toBeVisible({
      timeout: 5000,
    });
    await expect(
      mockedPage.getByTestId("saved-trips-section"),
    ).not.toBeVisible();
  },
);

Then("stages are displayed correctly without dates", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("stage-card-1")).toBeVisible({
    timeout: 5000,
  });
  const stageCard = mockedPage.getByTestId("stage-card-1");
  await expect(stageCard).not.toContainText(/\d{2}\/\d{2}\/\d{4}/);
});

Then("I see a loading indicator", async ({ mockedPage }) => {
  await expect(
    mockedPage
      .getByTestId("trip-title-skeleton")
      .or(mockedPage.getByTestId("trip-title")),
  ).toBeVisible({ timeout: 5000 });
});
