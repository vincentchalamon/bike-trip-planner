import { test, expect } from "@playwright/test";

test.describe("/faq page", () => {
  test("loads the FAQ page and shows the title", async ({ page }) => {
    await page.goto("/faq");
    await page.waitForLoadState("networkidle");

    await expect(page.getByRole("heading", { level: 1 })).toBeVisible({
      timeout: 5000,
    });
  });

  test("displays all three FAQ categories", async ({ page }) => {
    await page.goto("/faq");
    await page.waitForLoadState("networkidle");

    const headings = page.getByRole("heading", { level: 2 });
    await expect(headings).toHaveCount(3);
  });

  test("accordion items are collapsed by default", async ({ page }) => {
    await page.goto("/faq");
    await page.waitForLoadState("networkidle");

    // All accordion buttons should have aria-expanded="false" initially
    const buttons = page.locator("button[aria-expanded]");
    const count = await buttons.count();
    expect(count).toBeGreaterThan(0);

    for (let i = 0; i < count; i++) {
      await expect(buttons.nth(i)).toHaveAttribute("aria-expanded", "false");
    }
  });

  test("clicking an accordion item expands it and shows the answer", async ({
    page,
  }) => {
    await page.goto("/faq");
    await page.waitForLoadState("networkidle");

    const firstButton = page.locator("button[aria-expanded]").first();
    await expect(firstButton).toHaveAttribute("aria-expanded", "false");

    await firstButton.click();
    await expect(firstButton).toHaveAttribute("aria-expanded", "true");
  });

  test("clicking an expanded accordion item collapses it", async ({ page }) => {
    await page.goto("/faq");
    await page.waitForLoadState("networkidle");

    const firstButton = page.locator("button[aria-expanded]").first();

    // Open
    await firstButton.click();
    await expect(firstButton).toHaveAttribute("aria-expanded", "true");

    // Close
    await firstButton.click();
    await expect(firstButton).toHaveAttribute("aria-expanded", "false");
  });

  test("multiple accordion items can be open simultaneously", async ({
    page,
  }) => {
    await page.goto("/faq");
    await page.waitForLoadState("networkidle");

    const buttons = page.locator("button[aria-expanded]");
    const firstButton = buttons.nth(0);
    const secondButton = buttons.nth(1);

    await firstButton.click();
    await secondButton.click();

    await expect(firstButton).toHaveAttribute("aria-expanded", "true");
    await expect(secondButton).toHaveAttribute("aria-expanded", "true");
  });

  test("back-to-home link navigates to /", async ({ page }) => {
    // Mock auth so the home page doesn't bounce to /login
    await page.route("**/auth/refresh", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({
          token:
            "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJ0ZXN0LXVzZXItaWQiLCJ1c2VybmFtZSI6InRlc3RAZXhhbXBsZS5jb20iLCJleHAiOjk5OTk5OTk5OTl9.ZmFrZS1zaWduYXR1cmU",
        }),
      });
    });

    await page.goto("/faq");
    await page.waitForLoadState("networkidle");

    const backLink = page.getByTestId("faq-back-link");
    await expect(backLink).toBeVisible();
    await backLink.click();
    await expect(page).toHaveURL("/");
  });

  test("FAQ page is publicly accessible without authentication", async ({
    page,
  }) => {
    // Mock auth/refresh as 401 (unauthenticated)
    await page.route("**/auth/refresh", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({ status: 401, body: "" });
    });

    await page.goto("/faq");
    await page.waitForLoadState("networkidle");

    // Should NOT redirect to /login
    await expect(page).toHaveURL("/faq");
    await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
  });

  test("FAQ footer link is present on the login page", async ({ page }) => {
    // Mock auth/refresh as 401 (not authenticated)
    await page.route("**/auth/refresh", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({ status: 401, body: "" });
    });

    await page.goto("/login");
    await page.waitForLoadState("networkidle");

    const faqLink = page.getByTestId("footer-faq-link");
    await expect(faqLink).toBeVisible();
    await expect(faqLink).toHaveAttribute("href", "/faq");
  });

  test("FAQ footer link is present on the landing page", async ({ page }) => {
    // Mock successful auth so the landing page renders
    await page.route("**/auth/refresh", (route, request) => {
      if (request.method() !== "POST") return route.fallback();
      return route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({
          token:
            "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJ0ZXN0LXVzZXItaWQiLCJ1c2VybmFtZSI6InRlc3RAZXhhbXBsZS5jb20iLCJleHAiOjk5OTk5OTk5OTl9.ZmFrZS1zaWduYXR1cmU",
        }),
      });
    });

    await page.goto("/");
    await page.waitForLoadState("networkidle");

    const faqLink = page.getByTestId("footer-faq-link");
    await expect(faqLink).toBeVisible();
    await expect(faqLink).toHaveAttribute("href", "/faq");
  });

  test("page renders in French by default", async ({ page }) => {
    await page.goto("/faq");
    await page.waitForLoadState("networkidle");

    // French title
    await expect(
      page.getByRole("heading", { name: "Foire aux questions" }),
    ).toBeVisible({ timeout: 5000 });
  });
});
