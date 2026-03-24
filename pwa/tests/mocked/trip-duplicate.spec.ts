import { test, expect } from "../fixtures/base.fixture";
import { getTripId } from "../fixtures/api-mocks";

const NEW_TRIP_ID = "duplicate-trip-uuid-9999";

test.describe("Trip duplication", () => {
  test("duplicate button is disabled before trip is loaded", async ({
    mockedPage,
  }) => {
    await mockedPage
      .getByRole("button", { name: "Ouvrir les paramètres" })
      .click();
    await expect(
      mockedPage.getByTestId("duplicate-trip-button"),
    ).toBeDisabled();
  });

  test("duplicate button navigates to new trip on success", async ({
    submitUrl,
    mockedPage,
  }) => {
    await submitUrl();

    await mockedPage.route(
      `**/trips/${getTripId()}/duplicate`,
      (route, request) => {
        if (request.method() !== "POST") return route.fallback();
        return route.fulfill({
          status: 201,
          contentType: "application/ld+json",
          body: JSON.stringify({ id: NEW_TRIP_ID, computationStatus: {} }),
        });
      },
    );

    await mockedPage
      .getByRole("button", { name: "Ouvrir les paramètres" })
      .click();
    await mockedPage.getByTestId("duplicate-trip-button").click();

    await expect(
      mockedPage.getByText(/voyage dupliqué avec succès/i),
    ).toBeVisible({ timeout: 5000 });
    await expect(mockedPage).toHaveURL(new RegExp(NEW_TRIP_ID));
  });

  test("duplicate button shows error toast on API failure", async ({
    submitUrl,
    mockedPage,
  }) => {
    await submitUrl();

    await mockedPage.route(
      `**/trips/${getTripId()}/duplicate`,
      (route, request) => {
        if (request.method() !== "POST") return route.fallback();
        return route.fulfill({ status: 500, body: "" });
      },
    );

    await mockedPage
      .getByRole("button", { name: "Ouvrir les paramètres" })
      .click();
    await mockedPage.getByTestId("duplicate-trip-button").click();

    await expect(
      mockedPage.getByText(/impossible de dupliquer/i),
    ).toBeVisible({ timeout: 5000 });
    await expect(mockedPage).not.toHaveURL(new RegExp(NEW_TRIP_ID));
  });
});
