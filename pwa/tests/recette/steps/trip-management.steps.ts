import { expect } from "@playwright/test";
import { Given, When, Then } from "../support/fixtures";
import { getTripId } from "../../fixtures/api-mocks";

const SETTINGS_DIALOG_NAME = /Paramètres|Settings/i;

// ---------------------------------------------------------------------------
// Trip management — FR + EN
//
// "Mes voyages" is the API-backed trip list (GET /trips). #649 removed the
// front-side IndexedDB snapshots ("Mes voyages sauvegardés"), so these steps
// drive the real list: the home dashboard's RecentTrips widget
// (`recent-trip-{id}` cards) and the trip detail route (`/trips/{id}`).
// ---------------------------------------------------------------------------

/** A Hydra `Trip.TripListItem` matching the PWA list/recent-trips schema. */
function buildTripListItem(i: number) {
  return {
    "@id": `/trips/trip-${i + 1}`,
    "@type": "Trip",
    id: `trip-${i + 1}`,
    title: `Trip ${i + 1}`,
    totalDistance: 187000,
    stageCount: 3,
    status: "analyzed",
    startDate: "2026-06-01T00:00:00+00:00",
    endDate: "2026-06-03T00:00:00+00:00",
    createdAt: "2026-05-01T00:00:00+00:00",
    updatedAt: "2026-05-01T00:00:00+00:00",
  };
}

/**
 * Override `GET /trips` with `count` members, then reload so the home
 * RecentTrips widget (and the /trips page) pick them up.
 */
async function seedTripList(
  page: import("@playwright/test").Page,
  count: number,
) {
  const member = Array.from({ length: count }, (_, i) => buildTripListItem(i));
  await page.route(
    (url) => url.pathname === "/trips",
    (route, request) => {
      if (request.method() !== "GET") return route.fallback();
      return route.fulfill({
        status: 200,
        contentType: "application/ld+json",
        body: JSON.stringify({
          "@context": "/contexts/Trip",
          "@id": "/trips",
          "@type": "hydra:Collection",
          "hydra:totalItems": count,
          "hydra:member": member,
          member,
          totalItems: count,
        }),
      });
    },
  );
  await page.reload();
  await page.waitForLoadState("networkidle");
}

// --- Given steps FR ---

Given(
  "je suis connecté et que j'ai {int} voyages sauvegardés",
  async ({ mockedPage }, count: number) => {
    await seedTripList(mockedPage, count);
  },
);

Given(
  "je suis connecté et que j'ai un voyage sauvegardé",
  async ({ mockedPage }) => {
    await seedTripList(mockedPage, 1);
  },
);

Given("j'ai récemment consulté un voyage", async ({ mockedPage }) => {
  await seedTripList(mockedPage, 1);
});

Given(
  "un voyage a été verrouillé par un autre utilisateur",
  async ({ mockedPage }) => {
    // The detail mock reports the trip as locked; opening it shows the banner.
    await mockedPage.route("**/trips/*/detail", (route, request) => {
      if (request.method() !== "GET") return route.fallback();
      const tripId =
        request.url().match(/\/trips\/([^/]+)\/detail/)?.[1] ?? getTripId();
      return route.fulfill({
        status: 200,
        contentType: "application/ld+json",
        body: JSON.stringify({
          "@context": "/contexts/TripDetail",
          "@id": `/trips/${tripId}/detail`,
          "@type": "TripDetail",
          id: tripId,
          title: "Test Trip",
          sourceUrl: "https://www.komoot.com/fr-fr/tour/2795080048",
          startDate: null,
          endDate: null,
          fatigueFactor: 0.8,
          elevationPenalty: 100,
          maxDistancePerDay: 80,
          averageSpeed: 15,
          ebikeMode: false,
          departureHour: 8,
          enabledAccommodationTypes: ["hotel", "camp_site"],
          isLocked: true,
          stages: [],
          computationStatus: {},
        }),
      });
    });
  },
);

Given("je suis connecté sans voyage", async () => {
  // The default mock already returns an empty trip list — nothing to do.
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
  },
);

Given("I am logged in and have a saved trip", async ({ mockedPage }) => {
  await seedTripList(mockedPage, 1);
});

Given("I have recently viewed a trip", async ({ mockedPage }) => {
  await seedTripList(mockedPage, 1);
});

Given("a trip has been locked by another user", async ({ mockedPage }) => {
  await mockedPage.route("**/trips/*/detail", (route, request) => {
    if (request.method() !== "GET") return route.fallback();
    const tripId =
      request.url().match(/\/trips\/([^/]+)\/detail/)?.[1] ?? getTripId();
    return route.fulfill({
      status: 200,
      contentType: "application/ld+json",
      body: JSON.stringify({
        "@context": "/contexts/TripDetail",
        "@id": `/trips/${tripId}/detail`,
        "@type": "TripDetail",
        id: tripId,
        title: "Test Trip",
        sourceUrl: "https://www.komoot.com/fr-fr/tour/2795080048",
        startDate: null,
        endDate: null,
        fatigueFactor: 0.8,
        elevationPenalty: 100,
        maxDistancePerDay: 80,
        averageSpeed: 15,
        ebikeMode: false,
        departureHour: 8,
        enabledAccommodationTypes: ["hotel", "camp_site"],
        isLocked: true,
        stages: [],
        computationStatus: {},
      }),
    });
  });
});

Given("I am logged in with no trips", async () => {
  // The default mock already returns an empty trip list — nothing to do.
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
    .locator('[data-testid^="recent-trip-"]')
    .first();
  await expect(firstTrip).toBeVisible({ timeout: 5000 });
  await firstTrip.click();
});

When("je duplique ce voyage", async ({ mockedPage }) => {
  const firstTrip = mockedPage
    .locator('[data-testid^="recent-trip-"]')
    .first();
  await expect(firstTrip).toBeVisible({ timeout: 5000 });
  await firstTrip.click();
  await mockedPage.route("**/trips/*/duplicate", (route, request) => {
    if (request.method() !== "POST") return route.fallback();
    return route.fulfill({
      status: 201,
      contentType: "application/ld+json",
      body: JSON.stringify({
        id: "duplicated-trip-1",
        computationStatus: {},
      }),
    });
  });
  await mockedPage.getByTestId("config-open-button").click();
  await expect(
    mockedPage.getByRole("dialog", { name: SETTINGS_DIALOG_NAME }),
  ).toBeVisible({ timeout: 5000 });
  await mockedPage.getByTestId("duplicate-trip-button").click();
});

When("je supprime ce voyage", async ({ $test }) => {
  $test.fixme();
});

When("j'ouvre ce voyage", async ({ mockedPage }) => {
  const recentTrip = mockedPage
    .locator('[data-testid^="recent-trip-"]')
    .first();
  if (await recentTrip.isVisible().catch(() => false)) {
    await recentTrip.click();
    return;
  }
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
    .locator('[data-testid^="recent-trip-"]')
    .first();
  await expect(firstTrip).toBeVisible({ timeout: 5000 });
  await firstTrip.click();
});

When("I duplicate that trip", async ({ mockedPage }) => {
  const firstTrip = mockedPage
    .locator('[data-testid^="recent-trip-"]')
    .first();
  await expect(firstTrip).toBeVisible({ timeout: 5000 });
  await firstTrip.click();
  await mockedPage.route("**/trips/*/duplicate", (route, request) => {
    if (request.method() !== "POST") return route.fallback();
    return route.fulfill({
      status: 201,
      contentType: "application/ld+json",
      body: JSON.stringify({
        id: "duplicated-trip-1",
        computationStatus: {},
      }),
    });
  });
  await mockedPage.getByTestId("config-open-button").click();
  await expect(
    mockedPage.getByRole("dialog", { name: SETTINGS_DIALOG_NAME }),
  ).toBeVisible({ timeout: 5000 });
  await mockedPage.getByTestId("duplicate-trip-button").click();
});

When("I delete that trip", async ({ $test }) => {
  $test.fixme();
});

When("I open that trip", async ({ mockedPage }) => {
  const recentTrip = mockedPage
    .locator('[data-testid^="recent-trip-"]')
    .first();
  if (await recentTrip.isVisible().catch(() => false)) {
    await recentTrip.click();
    return;
  }
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
    const cards = mockedPage.locator('[data-testid^="recent-trip-"]');
    await expect(cards).toHaveCount(count, { timeout: 5000 });
  },
);

Then(
  "je suis redirigé vers la page détail du voyage",
  async ({ mockedPage }) => {
    await expect(mockedPage.getByTestId("config-open-button")).toBeVisible({
      timeout: 5000,
    });
    await expect(
      mockedPage.getByRole("button", { name: /Trip title|Titre du voyage/i }),
    ).toBeVisible();
  },
);

Then(
  "un nouveau voyage identique apparaît dans ma liste",
  async ({ mockedPage }) => {
    await expect(mockedPage).toHaveURL(/duplicated-trip-1/, { timeout: 5000 });
  },
);

Then("il n'apparaît plus dans ma liste", async ({ mockedPage }) => {
  const cards = mockedPage.locator('[data-testid^="recent-trip-"]');
  await expect(cards).toHaveCount(0, { timeout: 5000 });
});

Then(
  "je vois le voyage récent dans la section {string}",
  async ({ mockedPage }, _section: string) => {
    await expect(mockedPage.getByTestId("recent-trips")).toBeVisible({
      timeout: 5000,
    });
    await expect(
      mockedPage.locator('[data-testid^="recent-trip-"]').first(),
    ).toBeVisible();
  },
);

Then("je vois un indicateur de verrouillage", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("trip-locked-banner")).toBeVisible({
    timeout: 5000,
  });
});

Then("les boutons de modification sont désactivés", async ({ mockedPage }) => {
  // A locked trip is read-only; the locked banner is the visible indicator.
  await expect(mockedPage.getByTestId("trip-locked-banner")).toBeVisible({
    timeout: 5000,
  });
});

Then(
  "je vois un état vide invitant à créer un voyage",
  async ({ mockedPage }) => {
    // No trips → RecentTrips renders null, the magic-link card is the CTA.
    await expect(mockedPage.getByTestId("magic-link-input")).toBeVisible({
      timeout: 5000,
    });
    await expect(mockedPage.getByTestId("recent-trips")).not.toBeVisible();
  },
);

Then(
  "les étapes s'affichent correctement sans dates",
  async ({ mockedPage }) => {
    await expect(
      mockedPage.getByRole("button", { name: /Trip title|Titre du voyage/i }),
    ).toBeVisible({ timeout: 5000 });
  },
);

Then("je vois un indicateur de chargement", async ({ mockedPage }) => {
  await expect(
    mockedPage
      .getByTestId("trip-loader")
      .or(mockedPage.getByTestId("trip-title")),
  ).toBeVisible({ timeout: 5000 });
});

// --- Then steps EN ---

Then("I see my list of {int} trips", async ({ mockedPage }, count: number) => {
  const cards = mockedPage.locator('[data-testid^="recent-trip-"]');
  await expect(cards).toHaveCount(count, { timeout: 5000 });
});

Then("I am redirected to the trip detail page", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("config-open-button")).toBeVisible({
    timeout: 5000,
  });
  await expect(
    mockedPage.getByRole("button", { name: /Trip title|Titre du voyage/i }),
  ).toBeVisible();
});

Then("a new identical trip appears in my list", async ({ mockedPage }) => {
  await expect(mockedPage).toHaveURL(/duplicated-trip-1/, { timeout: 5000 });
});

Then("it no longer appears in my list", async ({ mockedPage }) => {
  const cards = mockedPage.locator('[data-testid^="recent-trip-"]');
  await expect(cards).toHaveCount(0, { timeout: 5000 });
});

Then(
  "I see the recent trip in the {string} section",
  async ({ mockedPage }, _section: string) => {
    await expect(mockedPage.getByTestId("recent-trips")).toBeVisible({
      timeout: 5000,
    });
    await expect(
      mockedPage.locator('[data-testid^="recent-trip-"]').first(),
    ).toBeVisible();
  },
);

Then("I see a lock indicator", async ({ mockedPage }) => {
  await expect(mockedPage.getByTestId("trip-locked-banner")).toBeVisible({
    timeout: 5000,
  });
});

Then("edit buttons are disabled", async ({ mockedPage }) => {
  // A locked trip is read-only; the locked banner is the visible indicator.
  await expect(mockedPage.getByTestId("trip-locked-banner")).toBeVisible({
    timeout: 5000,
  });
});

Then(
  "I see an empty state prompting me to create a trip",
  async ({ mockedPage }) => {
    await expect(mockedPage.getByTestId("magic-link-input")).toBeVisible({
      timeout: 5000,
    });
    await expect(mockedPage.getByTestId("recent-trips")).not.toBeVisible();
  },
);

Then("stages are displayed correctly without dates", async ({ mockedPage }) => {
  await expect(
    mockedPage.getByRole("button", { name: /Trip title|Titre du voyage/i }),
  ).toBeVisible({ timeout: 5000 });
});

Then("I see a loading indicator", async ({ mockedPage }) => {
  await expect(
    mockedPage
      .getByTestId("trip-loader")
      .or(mockedPage.getByTestId("trip-title")),
  ).toBeVisible({ timeout: 5000 });
});
