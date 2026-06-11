import { test, expect } from "@playwright/test";
import { FAKE_JWT_TOKEN } from "../fixtures/api-mocks";

const PAGES = [
  {
    path: "/legal",
    testid: "legal-back-link",
    title: "Mentions légales",
    toc: "legal-toc",
    sectionCount: 4,
    sampleSection: "legal-section-publisher",
    sampleTocLink: "legal-toc-host",
    sampleTarget: "#host",
  },
  {
    path: "/privacy",
    testid: "privacy-back-link",
    title: "Politique de confidentialité",
    toc: "privacy-toc",
    sectionCount: 9,
    sampleSection: "privacy-section-analytics",
    sampleTocLink: "privacy-toc-rights",
    sampleTarget: "#rights",
  },
] as const;

for (const page_ of PAGES) {
  const {
    path,
    testid,
    title,
    toc,
    sectionCount,
    sampleSection,
    sampleTocLink,
    sampleTarget,
  } = page_;

  test.describe(`${path} page`, () => {
    test("is publicly accessible without authentication", async ({ page }) => {
      await page.route("**/auth/refresh", (route, request) => {
        if (request.method() !== "POST") return route.fallback();
        return route.fulfill({ status: 401, body: "" });
      });

      await page.goto(path);
      await page.waitForLoadState("networkidle");

      // Should NOT redirect to /login
      await expect(page).toHaveURL(path);
      await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
    });

    test("renders the localized title in French by default", async ({
      page,
    }) => {
      await page.goto(path);
      await page.waitForLoadState("networkidle");

      await expect(page.getByRole("heading", { name: title })).toBeVisible({
        timeout: 5000,
      });
    });

    test("renders all sections with level-2 headings", async ({ page }) => {
      await page.goto(path);
      await page.waitForLoadState("networkidle");

      await expect(page.getByRole("heading", { level: 2 })).toHaveCount(
        sectionCount,
      );
      await expect(page.getByTestId(sampleSection)).toBeVisible();
    });

    test("shows a table of contents whose links jump to sections", async ({
      page,
    }) => {
      await page.goto(path);
      await page.waitForLoadState("networkidle");

      const tocNav = page.getByTestId(toc);
      await expect(tocNav).toBeVisible();

      const tocLink = page.getByTestId(sampleTocLink);
      await expect(tocLink).toHaveAttribute("href", sampleTarget);
      await tocLink.click();
      await expect(page).toHaveURL(`${path}${sampleTarget}`);
    });

    test("renders the global footer with legal and privacy links", async ({
      page,
    }) => {
      await page.goto(path);
      await page.waitForLoadState("networkidle");

      await expect(page.getByTestId("section-footer")).toBeVisible();

      const legalLink = page.getByTestId("footer-legal");
      const privacyLink = page.getByTestId("footer-privacy");
      await expect(legalLink).toHaveAttribute("href", "/legal");
      await expect(privacyLink).toHaveAttribute("href", "/privacy");
    });

    test("renders the public top bar header (#649)", async ({ page }) => {
      await page.goto(path);
      await page.waitForLoadState("networkidle");

      const header = page.getByTestId("public-top-bar");
      await expect(header).toBeVisible();
      await expect(page.getByTestId("public-top-bar-brand")).toHaveAttribute(
        "href",
        "/",
      );
    });

    test("back-to-home link navigates to /", async ({ page }) => {
      await page.route("**/auth/refresh", (route, request) => {
        if (request.method() !== "POST") return route.fallback();
        return route.fulfill({
          status: 200,
          contentType: "application/json",
          body: JSON.stringify({ token: FAKE_JWT_TOKEN }),
        });
      });

      await page.goto(path);
      await page.waitForLoadState("networkidle");

      const backLink = page.getByTestId(testid);
      await expect(backLink).toBeVisible();
      await backLink.click();
      await expect(page).toHaveURL("/");
    });
  });
}

test("privacy page mentions Plausible analytics", async ({ page }) => {
  await page.goto("/privacy");
  await page.waitForLoadState("networkidle");

  const section = page.getByTestId("privacy-section-analytics");
  await expect(section).toContainText("Plausible");
});
