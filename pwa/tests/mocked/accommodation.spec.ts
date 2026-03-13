import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  accommodationsFoundEvent,
  emptyAccommodationsFoundEvent,
  tripCompleteEvent,
} from "../fixtures/mock-data";

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
    // Click remove button on first accommodation
    const removeButtons = stageCard.getByRole("button", {
      name: "Supprimer l'hébergement",
    });
    await removeButtons.first().click();
    await expect(stageCard).not.toContainText("Camping Les Oliviers");
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
        body: JSON.stringify({ id: "test-trip-abc-123", computationStatus: {} }),
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
    await expandButton.click();

    // The request should have been made with radiusKm = 7
    await mockedPage.waitForTimeout(200);
    expect(scanRequestBody).toMatchObject({ radiusKm: 7 });
  });
});
