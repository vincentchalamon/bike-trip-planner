import { test, expect } from "../fixtures/base.fixture";
import { getTripId } from "../fixtures/api-mocks";

/**
 * E2E coverage for deleting a trip from the trip itself (recette #649).
 *
 * A red "Supprimer le voyage" button sits below "Partager ce voyage" in the
 * configuration drawer. It opens a destructive confirmation dialog and, on
 * confirm, calls `DELETE /trips/{id}` (the same endpoint as the "Mes voyages"
 * list) before navigating back to `/trips`.
 */
test.describe("Delete trip from the config panel", () => {
  test("confirming deletion calls the API and navigates to the trips list", async ({
    submitUrl,
    mockedPage,
  }) => {
    await submitUrl();

    let deleteCalled = false;
    await mockedPage.route(`**/trips/${getTripId()}`, (route, request) => {
      if (request.method() !== "DELETE") return route.fallback();
      deleteCalled = true;
      return route.fulfill({ status: 204, body: "" });
    });

    await mockedPage
      .getByRole("button", { name: "Ouvrir les paramètres" })
      .click();

    await mockedPage.getByTestId("delete-trip-button").click();

    // Destructive confirmation dialog appears; confirm the deletion.
    const dialog = mockedPage.getByTestId("delete-trip-dialog");
    await expect(dialog).toBeVisible({ timeout: 5000 });
    await mockedPage.getByTestId("delete-trip-dialog-confirm").click();

    await expect(mockedPage.getByText(/voyage supprimé/i)).toBeVisible({
      timeout: 5000,
    });
    await mockedPage.waitForURL(/\/trips$/, { timeout: 5000 });
    expect(deleteCalled).toBe(true);
  });

  test("shows an error toast when the deletion fails", async ({
    submitUrl,
    mockedPage,
  }) => {
    await submitUrl();

    await mockedPage.route(`**/trips/${getTripId()}`, (route, request) => {
      if (request.method() !== "DELETE") return route.fallback();
      return route.fulfill({ status: 500, body: "" });
    });

    await mockedPage
      .getByRole("button", { name: "Ouvrir les paramètres" })
      .click();
    await mockedPage.getByTestId("delete-trip-button").click();
    await mockedPage.getByTestId("delete-trip-dialog-confirm").click();

    await expect(mockedPage.getByText(/impossible de supprimer/i)).toBeVisible({
      timeout: 5000,
    });
  });
});
