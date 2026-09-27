import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import "@testing-library/jest-dom/vitest";
import { AuthorizedApplicationsSection } from "./authorized-applications-section";

const fetchAuthorizedApplications = vi.fn();
const revokeAuthorizedApplication = vi.fn();
const toastError = vi.fn();
const toastSuccess = vi.fn();

vi.mock("@/lib/api/client", () => ({
  fetchAuthorizedApplications: () => fetchAuthorizedApplications(),
  revokeAuthorizedApplication: (id: string) => revokeAuthorizedApplication(id),
  getLastRequestId: () => null,
}));

vi.mock("@/components/ui/sonner", () => ({
  toast: {
    error: (message: string) => toastError(message),
    success: (message: string) => toastSuccess(message),
  },
}));

// A translated string is bracketed, a raw one is not — otherwise a mock that echoes
// its key makes the fallback assertion below pass whether the fallback exists or not.
// `trips:read` is the only key this catalogue knows.
vi.mock("next-intl", () => {
  const translate = Object.assign(
    (key: string, values?: Record<string, string>) =>
      values ? `[${key}]:${Object.values(values).join(",")}` : `[${key}]`,
    { has: (key: string) => key === "trips:read" },
  );
  return {
    useTranslations: () => translate,
    useLocale: () => "fr",
  };
});

function application(overrides: Record<string, unknown> = {}) {
  return {
    id: "0199a0d0-0000-7000-8000-000000000001",
    name: "Example Agent",
    host: "agent.example.com",
    scopes: ["trips:read"],
    authorizedAt: "2026-09-20T10:00:00+00:00",
    lastUsedAt: null,
    ...overrides,
  };
}

describe("AuthorizedApplicationsSection", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    revokeAuthorizedApplication.mockResolvedValue(true);
  });

  it("lists an application with its host beside its name", async () => {
    fetchAuthorizedApplications.mockResolvedValue([application()]);

    render(<AuthorizedApplicationsSection />);

    expect(
      await screen.findByTestId("authorized-application-name"),
    ).toHaveTextContent("Example Agent");
    // The host is what a third party cannot choose, so it must be on screen too.
    expect(screen.getByTestId("authorized-application-host")).toHaveTextContent(
      "agent.example.com",
    );
  });

  it("says an application was never used rather than showing an empty date", async () => {
    fetchAuthorizedApplications.mockResolvedValue([application()]);

    render(<AuthorizedApplicationsSection />);

    await screen.findByTestId("authorized-application");
    expect(screen.getByText(/neverUsed/)).toBeInTheDocument();
  });

  it("falls back to the raw scope when the catalogue does not describe it", async () => {
    fetchAuthorizedApplications.mockResolvedValue([
      application({ scopes: ["trips:read", "trips:retired"] }),
    ]);

    render(<AuthorizedApplicationsSection />);

    await screen.findByTestId("authorized-application");
    // Known scope: translated. Unknown one: shown raw, as granted, not swallowed.
    expect(screen.getByText("[trips:read]")).toBeInTheDocument();
    expect(screen.getByText("trips:retired")).toBeInTheDocument();
  });

  it("shows an empty state when nothing is authorized", async () => {
    fetchAuthorizedApplications.mockResolvedValue([]);

    render(<AuthorizedApplicationsSection />);

    expect(
      await screen.findByTestId("authorized-applications-empty"),
    ).toBeInTheDocument();
  });

  it("shows an error state when the list cannot be loaded", async () => {
    fetchAuthorizedApplications.mockResolvedValue(null);

    render(<AuthorizedApplicationsSection />);

    expect(
      await screen.findByTestId("authorized-applications-error"),
    ).toBeInTheDocument();
    expect(screen.queryByTestId("authorized-application")).toBeNull();
  });

  it("revokes the grant and drops its row", async () => {
    fetchAuthorizedApplications.mockResolvedValue([application()]);

    render(<AuthorizedApplicationsSection />);

    fireEvent.click(await screen.findByTestId("revoke-application-button"));
    fireEvent.click(screen.getByTestId("revoke-application-dialog-confirm"));

    await waitFor(() =>
      expect(revokeAuthorizedApplication).toHaveBeenCalledWith(
        "0199a0d0-0000-7000-8000-000000000001",
      ),
    );
    await waitFor(() =>
      expect(screen.queryByTestId("authorized-application")).toBeNull(),
    );
    expect(toastSuccess).toHaveBeenCalled();
  });

  it("keeps the row when the revocation fails", async () => {
    fetchAuthorizedApplications.mockResolvedValue([application()]);
    revokeAuthorizedApplication.mockResolvedValue(false);

    render(<AuthorizedApplicationsSection />);

    fireEvent.click(await screen.findByTestId("revoke-application-button"));
    fireEvent.click(screen.getByTestId("revoke-application-dialog-confirm"));

    await waitFor(() => expect(toastError).toHaveBeenCalled());
    expect(screen.getByTestId("authorized-application")).toBeInTheDocument();
  });

  it("confirms without a typed keyword", async () => {
    fetchAuthorizedApplications.mockResolvedValue([application()]);

    render(<AuthorizedApplicationsSection />);

    fireEvent.click(await screen.findByTestId("revoke-application-button"));

    // Account deletion asks for a typed word because it cannot be undone. This can:
    // the user re-authorizes from the agent. The confirm button must be live at once.
    expect(
      screen.getByTestId("revoke-application-dialog-confirm"),
    ).not.toBeDisabled();
  });
});
