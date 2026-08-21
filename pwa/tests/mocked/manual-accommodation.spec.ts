import type { Locator, Page } from "@playwright/test";
import { test, expect } from "../fixtures/base.fixture";
import {
  routeParsedEvent,
  stagesComputedEvent,
  accommodationsFoundEvent,
  tripCompleteEvent,
  stageUpdatedEvent,
  stageUpdatedEventWithManualAccommodation,
} from "../fixtures/mock-data";

// StageResponse-ish body for the manual-add POST. The client only reads the HTTP
// status; the body just has to parse.
const STAGE_RESPONSE_BODY = JSON.stringify({
  "@type": "StageResponse",
  trip: { id: "test-trip-abc-123", computationStatus: {} },
});

/** Route the manual-accommodation POST to a given status. */
async function mockManualAccommodation(
  page: Page,
  status: number,
): Promise<void> {
  await page.route(
    "**/trips/*/stages/*/accommodations/manual",
    (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({
        status,
        contentType: "application/ld+json",
        body:
          status === 202
            ? STAGE_RESPONSE_BODY
            : JSON.stringify({
                detail: "Address could not be geocoded.",
              }),
      });
    },
  );
}

/** Open the manual-add form and fill title/address/price/link. */
async function fillManualForm(stageCard: Locator): Promise<void> {
  await stageCard
    .getByRole("button", { name: "Ajouter un hébergement" })
    .click();
  const form = stageCard.getByTestId("manual-accommodation-form");
  await expect(form).toBeVisible();
  await form.getByLabel("Nom de l'hébergement").fill("HomeExchange Grenoble");
  await form.getByLabel("Adresse").fill("5 rue Test, Grenoble");
  await form.getByLabel("Prix total").fill("90");
  await form
    .getByLabel("URL de l'hébergement")
    .fill("https://homeexchange.example/xyz");
}

test.describe("Manual (hors-app) accommodation", () => {
  test("adds, geocodes and selects a manual accommodation, feeding the budget", async ({
    submitUrl,
    injectEvent,
    injectSequence,
    mockedPage,
  }) => {
    await mockManualAccommodation(mockedPage, 202);
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      accommodationsFoundEvent(0),
      tripCompleteEvent(),
    ]);

    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard).toContainText("Camping Les Oliviers");

    await fillManualForm(stageCard);

    // Submit → optimistic recomputation hides the card behind its skeleton…
    await stageCard.getByRole("button", { name: "Enregistrer" }).click();
    await expect(stageCard).toBeHidden({ timeout: 3000 });

    // …then the resolved stages arrive over SSE (the affected stage + the next).
    await injectEvent(stageUpdatedEventWithManualAccommodation(0));
    await injectEvent(stageUpdatedEvent(1));

    // The manual accommodation is the selected one, rendered by the same block:
    // title, "Manuel" source badge and the postal address.
    await expect(stageCard).toContainText("HomeExchange Grenoble");
    await expect(stageCard).toContainText("Sélectionné");
    await expect(
      stageCard.getByTestId("accommodation-source-badge"),
    ).toHaveText("Manuel");
    await expect(stageCard.getByTestId("accommodation-address")).toContainText(
      "5 rue Test, Grenoble",
    );
    // The scanned candidates are gone — only the selected manual one remains.
    await expect(stageCard).not.toContainText("Camping Les Oliviers");

    // It participates in the trip budget exactly like a scanned accommodation.
    await expect(mockedPage.getByTestId("estimated-budget")).toBeVisible();
    await expect(mockedPage.getByTestId("estimated-budget")).toContainText("€");
  });

  test("surfaces an actionable error on a 422 geocoding failure, selecting nothing", async ({
    submitUrl,
    injectSequence,
    mockedPage,
  }) => {
    await mockManualAccommodation(mockedPage, 422);
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      accommodationsFoundEvent(0),
      tripCompleteEvent(),
    ]);

    const stageCard = mockedPage.getByTestId("stage-card-1");
    await expect(stageCard).toContainText("Camping Les Oliviers");

    await fillManualForm(stageCard);
    await stageCard.getByRole("button", { name: "Enregistrer" }).click();

    // The actionable geocode-failure message is surfaced (French locale)…
    await expect(
      mockedPage.getByText(
        "Adresse introuvable. Précise-la (ajoute une ville ou un code postal) et réessaie.",
      ),
    ).toBeVisible();
    // …and nothing was selected: no manual card, no "Sélectionné" badge.
    await expect(stageCard).not.toContainText("Sélectionné");
    await expect(stageCard).not.toContainText("HomeExchange Grenoble");
    await expect(
      stageCard.getByTestId("accommodation-source-badge"),
    ).toHaveCount(0);
  });

  test("loses the manual accommodation after deselection, without re-proposing it", async ({
    submitUrl,
    injectEvent,
    injectSequence,
    mockedPage,
  }) => {
    await mockManualAccommodation(mockedPage, 202);
    await submitUrl();
    await injectSequence([
      routeParsedEvent(),
      stagesComputedEvent(),
      accommodationsFoundEvent(0),
      tripCompleteEvent(),
    ]);

    const stageCard = mockedPage.getByTestId("stage-card-1");
    await fillManualForm(stageCard);
    await stageCard.getByRole("button", { name: "Enregistrer" }).click();
    await expect(stageCard).toBeHidden({ timeout: 3000 });
    await injectEvent(stageUpdatedEventWithManualAccommodation(0));
    await injectEvent(stageUpdatedEvent(1));
    await expect(stageCard).toContainText("HomeExchange Grenoble");
    await expect(stageCard).toContainText("Sélectionné");

    // Deselect → the standard scan + recompute path (selectedAccommodation null).
    await stageCard
      .getByRole("button", { name: "Désélectionner l'hébergement" })
      .click();
    await expect(stageCard).toBeHidden({ timeout: 3000 });
    // Resolve both stages with an empty candidate list (no accommodations_found
    // is injected): the deselect scan finds nothing to re-propose.
    await injectEvent(stageUpdatedEvent(0));
    await injectEvent(stageUpdatedEvent(1));

    // The manual accommodation is gone and NOT re-proposed as a candidate.
    await expect(stageCard).not.toContainText("HomeExchange Grenoble");
    await expect(stageCard).not.toContainText("Sélectionné");
    await expect(
      stageCard.getByTestId("accommodation-source-badge"),
    ).toHaveCount(0);
    // Re-entry is possible: the add affordance is back.
    await expect(
      stageCard.getByRole("button", { name: "Ajouter un hébergement" }),
    ).toBeVisible();
  });
});
