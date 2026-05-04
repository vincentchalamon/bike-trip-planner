import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import "@testing-library/jest-dom/vitest";
import { MagicLinkForm } from "./magic-link-form";

// `useTranslations` is replaced by an identity translator so the keys
// themselves act as the rendered text — this keeps the test independent from
// FR/EN copy edits while still asserting that the right key is consumed.
vi.mock("next-intl", () => ({
  useTranslations: () => {
    const fn = (key: string, values?: Record<string, unknown>) => {
      if (values && Object.keys(values).length > 0) {
        return `${key}:${JSON.stringify(values)}`;
      }
      return key;
    };
    fn.rich = (key: string, values?: Record<string, unknown>) =>
      values && "email" in values ? `${key}:${String(values.email)}` : key;
    return fn;
  },
}));

const requestMagicLink = vi.fn();

vi.mock("@/store/auth-store", () => ({
  useAuthStore: (
    selector: (state: { requestMagicLink: typeof requestMagicLink }) => unknown,
  ) => selector({ requestMagicLink }),
}));

describe("MagicLinkForm", () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
    requestMagicLink.mockReset();
  });

  it("renders the form state with email input + submit", () => {
    render(<MagicLinkForm />);

    expect(screen.getByTestId("magic-link-form")).toBeInTheDocument();
    expect(screen.getByTestId("magic-link-input")).toBeInTheDocument();
    expect(screen.getByTestId("magic-link-submit")).toBeInTheDocument();
  });

  it("shows an inline error when submitting an invalid email", () => {
    render(<MagicLinkForm />);

    fireEvent.change(screen.getByTestId("magic-link-input"), {
      target: { value: "not-an-email" },
    });
    fireEvent.click(screen.getByTestId("magic-link-submit"));

    expect(screen.getByTestId("magic-link-email-error")).toBeInTheDocument();
    expect(requestMagicLink).not.toHaveBeenCalled();
  });

  it("shows the inline required error when the field is empty", () => {
    render(<MagicLinkForm />);

    fireEvent.click(screen.getByTestId("magic-link-submit"));

    expect(screen.getByTestId("magic-link-email-error")).toBeInTheDocument();
    expect(requestMagicLink).not.toHaveBeenCalled();
  });

  it("transitions to the sent state after a valid submit", async () => {
    requestMagicLink.mockResolvedValue(undefined);
    render(<MagicLinkForm cooldownSeconds={3} />);

    fireEvent.change(screen.getByTestId("magic-link-input"), {
      target: { value: "user@example.com" },
    });

    await act(async () => {
      fireEvent.click(screen.getByTestId("magic-link-submit"));
      await Promise.resolve();
    });

    expect(requestMagicLink).toHaveBeenCalledWith("user@example.com");
    const sent = screen.getByTestId("magic-link-sent");
    expect(sent).toBeInTheDocument();
    expect(sent.getAttribute("data-substate")).toBe("sent");
    expect(screen.getByTestId("magic-link-resend")).toBeDisabled();
  });

  it("activates the resend button once the cooldown elapses", async () => {
    requestMagicLink.mockResolvedValue(undefined);
    render(<MagicLinkForm cooldownSeconds={2} />);

    fireEvent.change(screen.getByTestId("magic-link-input"), {
      target: { value: "user@example.com" },
    });
    await act(async () => {
      fireEvent.click(screen.getByTestId("magic-link-submit"));
      await Promise.resolve();
    });

    // Tick past the cooldown — interval ticks once per second.
    await act(async () => {
      vi.advanceTimersByTime(2500);
    });

    expect(
      screen.getByTestId("magic-link-sent").getAttribute("data-substate"),
    ).toBe("sent-ready");
    expect(screen.getByTestId("magic-link-resend")).toBeEnabled();
  });

  it("renders the expired state when initialState=expired", () => {
    render(<MagicLinkForm initialState="expired" />);

    expect(screen.getByTestId("magic-link-expired")).toBeInTheDocument();
    expect(
      screen.getByTestId("magic-link-request-new"),
    ).toBeInTheDocument();
  });

  it("returns to the form state from the expired state", () => {
    render(<MagicLinkForm initialState="expired" />);

    fireEvent.click(screen.getByTestId("magic-link-request-new"));

    expect(screen.getByTestId("magic-link-form")).toBeInTheDocument();
  });
});
