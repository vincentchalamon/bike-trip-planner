import type { Page } from "@playwright/test";

export interface MockApiOptions {
  postTripStatus?: number;
  postTripBody?: Record<string, unknown>;
  deleteStageFail?: boolean;
  addStageFail?: boolean;
}

const TRIP_ID = "test-trip-abc-123";

const defaultTripResponse = {
  "@context": "/contexts/Trip",
  "@id": `/trips/${TRIP_ID}`,
  "@type": "Trip",
  id: TRIP_ID,
  computationStatus: {},
};

export function getTripId(): string {
  return TRIP_ID;
}

export async function mockAllApis(
  page: Page,
  options: MockApiOptions = {},
): Promise<void> {
  const {
    postTripStatus = 202,
    postTripBody,
    deleteStageFail = false,
    addStageFail = false,
  } = options;

  // POST /trips — create trip
  await page.route("**/trips", (route, request) => {
    if (request.method() !== "POST") return route.fallback();
    return route.fulfill({
      status: postTripStatus,
      contentType: "application/ld+json",
      body: JSON.stringify(postTripBody ?? defaultTripResponse),
    });
  });

  // PATCH /trips/{id} — update trip
  await page.route("**/trips/*", (route, request) => {
    if (request.method() !== "PATCH") return route.fallback();
    const url = request.url();
    // Skip stage-related PATCH routes
    if (url.includes("/stages/")) return route.fallback();
    return route.fulfill({
      status: 202,
      contentType: "application/ld+json",
      body: JSON.stringify(defaultTripResponse),
    });
  });

  // DELETE /trips/{id}/stages/{index}
  await page.route("**/trips/*/stages/*", (route, request) => {
    if (request.method() !== "DELETE") return route.fallback();
    if (deleteStageFail) {
      return route.fulfill({
        status: 400,
        contentType: "application/ld+json",
        body: JSON.stringify({
          "@type": "hydra:Error",
          "hydra:title": "Bad Request",
          detail: "Impossible de supprimer l'etape.",
        }),
      });
    }
    return route.fulfill({ status: 202, body: "" });
  });

  // POST /trips/{id}/stages — add stage
  await page.route("**/trips/*/stages", (route, request) => {
    if (request.method() !== "POST") return route.fallback();
    if (addStageFail) {
      return route.fulfill({
        status: 400,
        contentType: "application/ld+json",
        body: JSON.stringify({
          "@type": "hydra:Error",
          "hydra:title": "Bad Request",
          detail: "Impossible d'ajouter l'etape.",
        }),
      });
    }
    return route.fulfill({
      status: 202,
      contentType: "application/ld+json",
      body: JSON.stringify({
        "@type": "StageResponse",
        index: 1,
      }),
    });
  });

  // PATCH /trips/{id}/stages/{index} — update stage
  await page.route("**/trips/*/stages/*", (route, request) => {
    if (request.method() !== "PATCH") return route.fallback();
    return route.fulfill({
      status: 202,
      contentType: "application/ld+json",
      body: JSON.stringify({ "@type": "StageResponse" }),
    });
  });

  // GET /geocode/reverse — deterministic place names
  await page.route("**/geocode/reverse*", (route) => {
    const url = new URL(route.request().url());
    const lat = parseFloat(url.searchParams.get("lat") ?? "0");
    const lon = parseFloat(url.searchParams.get("lon") ?? "0");
    const name = placeNameFromCoords(lat, lon);
    return route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({
        results: [{ name, displayName: name, lat, lon, type: "city" }],
      }),
    });
  });

  // GET /geocode/search — place search
  await page.route("**/geocode/search*", (route) => {
    return route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify([
        {
          name: "Aubenas",
          displayName: "Aubenas, Ardeche",
          lat: 44.62,
          lon: 4.39,
          type: "city",
        },
      ]),
    });
  });

  // GET /.well-known/mercure — abort real SSE (we use __test_mercure_event)
  await page.route("**/.well-known/mercure*", (route) => route.abort());

  // POST /accommodations/scrape
  await page.route("**/accommodations/scrape", (route) =>
    route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({
        name: "Camping du Soleil",
        type: "camp_site",
        priceMin: 15,
        priceMax: 22,
      }),
    }),
  );
}

function placeNameFromCoords(lat: number, lon: number): string {
  const places: Record<string, string> = {
    "44.735-4.598": "Aubenas",
    "44.532-4.392": "Vals-les-Bains",
    "44.295-4.087": "Les Vans",
    "44.112-3.876": "Villefort",
  };
  const key = `${lat.toFixed(3)}-${lon.toFixed(3)}`;
  return places[key] ?? `Lieu (${lat.toFixed(2)}, ${lon.toFixed(2)})`;
}
