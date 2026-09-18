import { describe, expect, it, vi, beforeEach } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";

const updateAccountLocale = vi.fn();
const setLocale = vi.fn();
const refresh = vi.fn();
let authenticated = true;

vi.mock("next-intl", () => ({
  useLocale: () => "fr",
  useTranslations: () => (key: string) => key,
}));
vi.mock("next/navigation", () => ({ useRouter: () => ({ refresh }) }));
vi.mock("@/i18n/set-locale", () => ({
  setLocale: (locale: string) => setLocale(locale),
}));
vi.mock("@/lib/api/client", () => ({
  updateAccountLocale: (locale: string) => updateAccountLocale(locale),
}));
vi.mock("@/store/auth-store", () => ({
  useAuthStore: (selector: (s: { isAuthenticated: boolean }) => unknown) =>
    selector({ isAuthenticated: authenticated }),
}));

// Radix's Select needs real pointer events to open, which fireEvent does not
// emit. The subject here is the change handler, not the popover, so the
// primitive is reduced to a button that fires onValueChange directly.
vi.mock("@/components/ui/select", () => ({
  Select: ({
    children,
    onValueChange,
  }: {
    children: React.ReactNode;
    onValueChange: (value: string) => void;
  }) => (
    <div>
      <button data-testid="pick-en" onClick={() => onValueChange("en")} />
      {children}
    </div>
  ),
  SelectContent: ({ children }: { children: React.ReactNode }) => (
    <>{children}</>
  ),
  SelectItem: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  SelectTrigger: ({ children }: { children: React.ReactNode }) => (
    <>{children}</>
  ),
}));

import { LocaleSwitcher } from "./locale-switcher";

/**
 * The server renders a trip's alerts in the account's locale, not from
 * Accept-Language (ADR-063). Switching the interface language therefore has to
 * persist the choice, otherwise the UI flips while the trips keep answering in
 * the previous language.
 */
describe("LocaleSwitcher", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    authenticated = true;
  });

  it("persists the new language on the account when signed in", () => {
    render(<LocaleSwitcher />);

    fireEvent.click(screen.getByTestId("pick-en"));

    expect(setLocale).toHaveBeenCalledWith("en");
    expect(updateAccountLocale).toHaveBeenCalledWith("en");
  });

  it("does not call the API when signed out", () => {
    // Also mounted on the public top bar, where there is no account to update.
    authenticated = false;
    render(<LocaleSwitcher />);

    fireEvent.click(screen.getByTestId("pick-en"));

    expect(setLocale).toHaveBeenCalledWith("en");
    expect(updateAccountLocale).not.toHaveBeenCalled();
  });
});
