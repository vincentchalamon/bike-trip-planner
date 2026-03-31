import type { Page } from "@playwright/test";

export interface MockApiOptions {
  postTripStatus?: number;
  postTripBody?: Record<string, unknown>;
  deleteStageFail?: boolean;
  addStageFail?: boolean;
}

const TRIP_ID = "test-trip-abc-123";

/**
 * Fake JWT token for test authentication.
 * Payload: {"sub":"test-user-id","username":"test@example.com","exp":9999999999}
 * Matches LexikJWTBundle format (username) + JwtCreatedListener (sub).
 * Header/payload are valid base64url; signature is a fake placeholder.
 */
export const FAKE_JWT_TOKEN =
  "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJ0ZXN0LXVzZXItaWQiLCJ1c2VybmFtZSI6InRlc3RAZXhhbXBsZS5jb20iLCJleHAiOjk5OTk5OTk5OTl9.ZmFrZS1zaWduYXR1cmU";

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

  // POST /auth/refresh — return fake JWT so AuthGuard's silentRefresh succeeds
  await page.route("**/auth/refresh", (route, request) => {
    if (request.method() !== "POST") return route.fallback();
    return route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({ token: FAKE_JWT_TOKEN }),
    });
  });

  // Capture pacing settings from POST body so the detail mock can echo them back
  let lastPostPacingSettings: Record<string, unknown> = {};

  // POST /trips — create trip
  await page.route("**/trips", (route, request) => {
    if (request.method() !== "POST") return route.fallback();
    try {
      const body = JSON.parse(request.postData() ?? "{}") as Record<
        string,
        unknown
      >;
      lastPostPacingSettings = {
        fatigueFactor: body.fatigueFactor,
        elevationPenalty: body.elevationPenalty,
        maxDistancePerDay: body.maxDistancePerDay,
        averageSpeed: body.averageSpeed,
        ebikeMode: body.ebikeMode,
        departureHour: body.departureHour,
      };
    } catch {
      /* ignore parse errors */
    }
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

  // PATCH /trips/{id}/stages/{index}/accommodation — select/deselect accommodation
  await page.route("**/trips/*/stages/*/accommodation", (route, request) => {
    if (request.method() !== "PATCH") return route.fallback();
    return route.fulfill({
      status: 202,
      contentType: "application/ld+json",
      body: JSON.stringify({ "@type": "StageResponse" }),
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

  // GET /trips/{id}/stages/{index}.gpx — serve mock GPX
  await page.route("**/trips/*/stages/*.gpx", (route) =>
    route.fulfill({
      status: 200,
      contentType: "application/gpx+xml",
      body: `<?xml version="1.0"?><gpx><trk><trkseg><trkpt lat="44.7" lon="4.5"><ele>280</ele></trkpt></trkseg></trk></gpx>`,
    }),
  );

  // GET /trips — list of trips (recent-trips widget + trips page)
  // Use URL predicate to match /trips with or without query params,
  // but NOT sub-paths like /trips/{id}/detail.
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
          "hydra:totalItems": 0,
          "hydra:member": [],
          member: [],
          totalItems: 0,
        }),
      });
    },
  );

  // GET /trips/{id}/detail — load trip on /trips/[id] page
  // Echo back pacing settings from the last POST so tests relying on the
  // initial store values (e.g. pacing-settings.spec.ts) see consistent data.
  await page.route("**/trips/*/detail", (route, request) => {
    if (request.method() !== "GET") return route.fallback();
    const tripId =
      request.url().match(/\/trips\/([^/]+)\/detail/)?.[1] ?? TRIP_ID;
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
        fatigueFactor: (lastPostPacingSettings.fatigueFactor as number) ?? 0.8,
        elevationPenalty:
          (lastPostPacingSettings.elevationPenalty as number) ?? 100,
        maxDistancePerDay:
          (lastPostPacingSettings.maxDistancePerDay as number) ?? 80,
        averageSpeed: (lastPostPacingSettings.averageSpeed as number) ?? 15,
        ebikeMode: (lastPostPacingSettings.ebikeMode as boolean) ?? false,
        departureHour: (lastPostPacingSettings.departureHour as number) ?? 8,
        enabledAccommodationTypes: [
          "camp_site",
          "hotel",
          "hostel",
          "chalet",
          "guest_house",
          "motel",
          "alpine_hut",
        ],
        isLocked: false,
        stages: [],
        computationStatus: {},
      }),
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

  // POST /trips/{id}/accommodations/scan — re-scan accommodations with custom radius
  await page.route("**/trips/*/accommodations/scan", (route, request) => {
    if (request.method() !== "POST") return route.fallback();
    return route.fulfill({
      status: 202,
      contentType: "application/ld+json",
      body: JSON.stringify(defaultTripResponse),
    });
  });

  // POST /trips/{id}/stages/{index}/poi-waypoint — add POI as waypoint
  await page.route("**/trips/*/stages/*/poi-waypoint", (route, request) => {
    if (request.method() !== "POST") return route.fallback();
    return route.fulfill({
      status: 202,
      contentType: "application/ld+json",
      body: JSON.stringify({ "@type": "StageResponse" }),
    });
  });
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
