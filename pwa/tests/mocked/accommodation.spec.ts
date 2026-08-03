import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  accommodationsFoundEvent,
  emptyAccommodationsFoundEvent,
  tripCompleteEvent,
} from "../fixtures/mock-data";
import {
  LOADED_TRIP_DETAIL_STAGES,
  mockLoadedTripDetail,
} from "../fixtures/api-mocks";

test.describe("Accommodations", () => {
  test("shows accommodations from SSE events", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      accommodationsFoundEvent(0),
      tripCompleteEvent(),
    ]);
    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard).toContainText("Camping Les Oliviers");
    await expect(stageCard).toContainText("Hotel du Pont");
    // Check accommodation type labels
    await expect(stageCard).toContainText("Camping");
    await expect(stageCard).toContainText("Hôtel");
    // Check distance badges
    await expect(stageCard).toContainText("1.2 km");
    await expect(stageCard).toContainText("0.5 km");
  });

  test("normalizes malformed OSM website tags (issue #867)", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      accommodationsFoundEvent(0),
      tripCompleteEvent(),
    ]);
    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard).toContainText("Camping Les Oliviers");

    // Schemeless `www.camping-les-oliviers.fr` becomes an absolute link…
    const links = stageCard.getByTestId("accommodation-website-link");
    await expect(links).toHaveCount(1);
    await expect(links).toHaveAttribute(
      "href",
      "https://www.camping-les-oliviers.fr",
    );
    await expect(links).toHaveText(/www\.camping-les-oliviers\.fr/);
    // …while the unusable value on "Hotel du Pont" renders no link at all
    // (and no error boundary: the card is still there).
    await expect(stageCard).toContainText("Hotel du Pont");
  });

  test("offers a tel: link and an OSM link on an OSM entry (issue #873)", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      accommodationsFoundEvent(0),
      tripCompleteEvent(),
    ]);
    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard).toContainText("Hotel du Pont");

    const phoneLink = stageCard.getByTestId("accommodation-phone-link");
    await expect(phoneLink).toHaveCount(1);
    await expect(phoneLink).toHaveAttribute("href", "tel:+33 4 75 00 00 00");

    // The link addresses the object by its own type: the fixture is a `way`, so
    // a hardcoded `node` would silently point at an unrelated feature.
    const osmLink = stageCard.getByTestId("accommodation-osm-link");
    await expect(osmLink).toHaveCount(1);
    await expect(osmLink).toHaveAttribute(
      "href",
      "https://www.openstreetmap.org/way/42",
    );

    // The DataTourisme entry has no OSM identity and no phone: no stray links.
    await expect(stageCard).toContainText("Camping Les Oliviers");
  });

  test("adds manual accommodation", async ({ createFullTrip, mockedPage }) => {
    await createFullTrip();
    const stageCard = mockedPage.getByTestId("stage-card-1");
    // Click "Ajouter un hébergement"
    await stageCard
      .getByRole("button", { name: "Ajouter un hébergement" })
      .click();
    // Form should appear with URL input focused
    const nameInput = stageCard.getByRole("textbox", {
      name: "Nom de l'hébergement",
    });
    await expect(nameInput).toBeVisible();
  });

  test("removes accommodation", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      accommodationsFoundEvent(0),
      tripCompleteEvent(),
    ]);
    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard).toContainText("Camping Les Oliviers");
    // Click remove button on first accommodation (Hotel du Pont — 0.5km, sorted first by distance)
    const removeButtons = stageCard.getByRole("button", {
      name: "Supprimer l'hébergement",
    });
    await removeButtons.first().click();
    await expect(stageCard).not.toContainText("Hotel du Pont");
  });

  test("hides distance badge when distanceToEndPoint is zero", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      {
        type: "accommodations_found",
        data: {
          stageIndex: 0,
          accommodations: [
            {
              name: "Camping Zero Distance",
              type: "camp_site",
              lat: 44.5,
              lon: 4.38,
              estimatedPriceMin: 10,
              estimatedPriceMax: 15,
              isExactPrice: false,
              possibleClosed: false,
              distanceToEndPoint: 0,
              source: "osm",
            },
          ],
        },
      },
      tripCompleteEvent(),
    ]);
    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard).toContainText("Camping Zero Distance");
    // Distance badge should not be rendered when distance is 0
    await expect(stageCard).not.toContainText("0 km");
  });

  test("no accommodation panel on last stage", async ({
    createFullTrip,
    mockedPage,
  }) => {
    await createFullTrip();
    const lastStage = mockedPage.getByTestId("stage-card-3");
    await expect(lastStage).toBeVisible();
    // Last stage should not have the "Ajouter un hébergement" button
    await expect(
      lastStage.getByRole("button", { name: "Ajouter un hébergement" }),
    ).toBeHidden();
  });

  test("shows no-accommodation message with radius when no results", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      emptyAccommodationsFoundEvent(0, 5),
      tripCompleteEvent(),
    ]);
    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard).toContainText("5 km");
    // Expand radius button should be visible
    await expect(
      stageCard.getByRole("button", { name: /7 km/i }),
    ).toBeVisible();
  });

  test("shows expand radius button when accommodations found and radius below max", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      accommodationsFoundEvent(0, 5),
      tripCompleteEvent(),
    ]);
    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard).toContainText("Camping Les Oliviers");
    // Expand radius suggestion should be available
    await expect(
      stageCard.getByRole("button", { name: /7 km/i }),
    ).toBeVisible();
  });

  test("hides expand radius button when max radius reached", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      accommodationsFoundEvent(0, 15),
      tripCompleteEvent(),
    ]);
    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard).toContainText("Camping Les Oliviers");
    // No expand button when at max radius
    await expect(
      stageCard.getByRole("button", { name: /17 km/i }),
    ).toBeHidden();
  });

  test("clicking expand radius button triggers accommodation re-scan", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    let scanRequestBody: unknown = null;

    // Intercept the accommodation scan request
    await mockedPage.route("**/trips/*/accommodations/scan", (route, req) => {
      if (req.method() === "POST") {
        scanRequestBody = JSON.parse(req.postData() ?? "{}");
      }
      return route.fulfill({
        status: 202,
        contentType: "application/ld+json",
        body: JSON.stringify({
          id: "test-trip-abc-123",
          computationStatus: {},
        }),
      });
    });

    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      emptyAccommodationsFoundEvent(0, 5),
      tripCompleteEvent(),
    ]);

    const stageCard = mockedPage.getByTestId("stage-card-1");
    const expandButton = stageCard.getByRole("button", { name: /7 km/i });
    await expect(expandButton).toBeVisible();

    // Set up the request promise BEFORE clicking to avoid race condition
    const requestPromise = mockedPage.waitForRequest(
      (req) =>
        req.url().includes("/accommodations/scan") && req.method() === "POST",
    );
    await expandButton.click();
    await requestPromise;

    expect(scanRequestBody).toMatchObject({ radiusKm: 7 });
  });

  test("keeps the enrichment and the source badge after a reload", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    const scan = accommodationsFoundEvent(0);
    // Narrowing keeps the reload payload in sync with the SSE fixture.
    const persisted =
      scan.type === "accommodations_found" ? scan.data.accommodations : [];

    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      scan,
      tripCompleteEvent(),
    ]);

    const liveCard = mockedPage.getByTestId("stage-card-1");
    await expect(liveCard).toContainText("DataTourisme");
    await expect(
      liveCard.getByRole("link", { name: "Voir sur Wikipedia" }),
    ).toBeVisible();

    // The store is in-memory (no persist), so a reload re-hydrates the cards
    // from GET /trips/{id}/detail. Before issue #870 neither the JSONB write nor
    // that payload carried source / description / imageUrl / wikipediaUrl /
    // openingHours, so the reloaded card came back as a bare OSM entry.
    const tripUrl = mockedPage.url();
    await mockLoadedTripDetail(mockedPage, {
      stages: LOADED_TRIP_DETAIL_STAGES.map((stage, index) =>
        index === 0 ? { ...stage, accommodations: persisted } : stage,
      ),
    });
    await mockedPage.goto(tripUrl);

    const reloadedCard = mockedPage.getByTestId("stage-card-1");
    await expect(reloadedCard).toContainText("Camping Les Oliviers");
    await expect(reloadedCard).toContainText("DataTourisme");
    await expect(
      reloadedCard.getByRole("link", { name: "Voir sur Wikipedia" }),
    ).toHaveAttribute("href", "https://fr.wikipedia.org/wiki/Camping");
    await expect(
      reloadedCard.getByRole("img", { name: "Camping Les Oliviers" }),
    ).toHaveAttribute("src", "https://example.com/oliviers.jpg");
  });
});
