import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, waitFor, fireEvent } from "@testing-library/react";
import "@testing-library/jest-dom/vitest";

vi.mock("next-intl", () => ({
  useTranslations: () => (key: string) => key,
}));

vi.mock("next/navigation", () => ({
  useParams: () => ({ handle: "a-handle" }),
}));

const fetchPendingConsent = vi.fn();
const decidePendingConsent = vi.fn();

vi.mock("@/lib/api/client", () => ({
  fetchPendingConsent: (handle: string) => fetchPendingConsent(handle),
  decidePendingConsent: (handle: string, decision: string) =>
    decidePendingConsent(handle, decision),
}));

import OAuthConsentPage from "./consent-page";

const pending = (overrides: Record<string, unknown> = {}) => ({
  handle: "a-handle",
  clientName: "Example Agent",
  scopes: ["trips:read"],
  redirectHost: "agent.example.com",
  redirectsToLoopback: false,
  continueUrl: "/oauth/authorize?response_type=code",
  ...overrides,
});

describe("OAuthConsentPage", () => {
  beforeEach(() => {
    fetchPendingConsent.mockResolvedValue(pending());
    decidePendingConsent.mockResolvedValue(true);
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  /**
   * `clientName` is text a third party chose for itself, fetched from a Client ID Metadata
   * Document. It has to reach the screen as CONTENT and never as markup — a later "improve
   * the client branding" change reaching for dangerouslySetInnerHTML would otherwise ship
   * an XSS on the one page where a user grants permissions.
   */
  it("renders a hostile client name as literal text", async () => {
    fetchPendingConsent.mockResolvedValue(
      pending({ clientName: "<img src=x onerror=alert(1)>Totally Safe" }),
    );

    render(<OAuthConsentPage />);

    const name = await screen.findByTestId("oauth-consent-client");
    expect(name).toHaveTextContent("<img src=x onerror=alert(1)>Totally Safe");
    expect(name.querySelector("img")).toBeNull();
    expect(name.innerHTML).not.toContain("<img");
  });

  /**
   * Nothing can prove a loopback address belongs to the application the user thinks it
   * does, so the warning is the only thing standing between them and approving whatever
   * happens to be listening on that port.
   */
  it("warns when the client only ever returns to a loopback address", async () => {
    fetchPendingConsent.mockResolvedValue(
      pending({ redirectHost: "127.0.0.1", redirectsToLoopback: true }),
    );

    render(<OAuthConsentPage />);

    expect(
      await screen.findByTestId("oauth-consent-loopback-warning"),
    ).toBeInTheDocument();
    expect(screen.getByTestId("oauth-consent-redirect")).toHaveTextContent(
      "127.0.0.1",
    );
  });

  it("does not warn when the client returns to a real host", async () => {
    render(<OAuthConsentPage />);

    await screen.findByTestId("oauth-consent");
    expect(
      screen.queryByTestId("oauth-consent-loopback-warning"),
    ).not.toBeInTheDocument();
  });

  it("shows the scopes the server resolved, one per line", async () => {
    fetchPendingConsent.mockResolvedValue(
      pending({ scopes: ["trips:read", "trips:write"] }),
    );

    render(<OAuthConsentPage />);

    const list = await screen.findByTestId("oauth-consent-scopes");
    expect(list.querySelectorAll("li")).toHaveLength(2);
  });

  /**
   * The decision has to be recorded BEFORE the browser goes back: the return leg is a plain
   * top-level GET that any page can trigger, and what stops it being an approval anybody
   * could cause is that a decision is already on file.
   */
  it("records the decision before navigating back", async () => {
    let navigated: string | null = null;
    Object.defineProperty(window, "location", {
      value: {
        set href(value: string) {
          navigated = value;
        },
      },
      writable: true,
    });

    render(<OAuthConsentPage />);

    fireEvent.click(await screen.findByTestId("oauth-consent-approve"));

    await waitFor(() =>
      expect(decidePendingConsent).toHaveBeenCalledWith("a-handle", "approve"),
    );
    await waitFor(() =>
      expect(navigated).toBe("/oauth/authorize?response_type=code"),
    );
  });

  it("does not navigate when the decision could not be recorded", async () => {
    decidePendingConsent.mockResolvedValue(false);

    render(<OAuthConsentPage />);

    fireEvent.click(await screen.findByTestId("oauth-consent-approve"));

    expect(
      await screen.findByTestId("oauth-consent-expired"),
    ).toBeInTheDocument();
  });

  it("says so when there is no pending authorization", async () => {
    fetchPendingConsent.mockResolvedValue(null);

    render(<OAuthConsentPage />);

    expect(
      await screen.findByTestId("oauth-consent-expired"),
    ).toBeInTheDocument();
  });
});
