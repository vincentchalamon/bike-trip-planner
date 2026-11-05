import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import "@testing-library/jest-dom/vitest";
import { AccountRail } from "./account-rail";

const logout = vi.fn();
const toastError = vi.fn();

vi.mock("next-intl", () => ({
  useTranslations: () => (key: string) => key,
}));

vi.mock("@/components/ui/sonner", () => ({
  toast: { error: (message: string) => toastError(message) },
}));

vi.mock("@/store/auth-store", () => ({
  useAuthStore: (
    selector: (s: {
      user: { email: string };
      logout: () => Promise<void>;
    }) => unknown,
  ) => selector({ user: { email: "rider@example.com" }, logout }),
}));

describe("AccountRail logout", () => {
  const assign = vi.fn();
  const originalLocation = window.location;

  beforeEach(() => {
    Object.defineProperty(window, "location", {
      value: { assign },
      writable: true,
      configurable: true,
    });
  });

  afterEach(() => {
    Object.defineProperty(window, "location", {
      value: originalLocation,
      writable: true,
      configurable: true,
    });
    vi.clearAllMocks();
  });

  // A client-side route change would keep the session's stores in memory.
  it("reloads the page on the landing once logged out", async () => {
    logout.mockResolvedValue(undefined);

    render(<AccountRail />);
    fireEvent.click(screen.getByTestId("logout-button"));

    await waitFor(() => expect(assign).toHaveBeenCalledWith("/"));
    expect(logout).toHaveBeenCalledTimes(1);
  });

  it("stays on the page when the logout fails", async () => {
    logout.mockRejectedValue(new Error("down"));

    render(<AccountRail />);
    fireEvent.click(screen.getByTestId("logout-button"));

    await waitFor(() => expect(toastError).toHaveBeenCalled());
    expect(assign).not.toHaveBeenCalled();
  });
});
