import { describe, it, expect, vi, afterEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import "@testing-library/jest-dom/vitest";
import { AiRefinementCard } from "./ai-refinement-card";

// next-intl is replaced by an identity translator so the keys themselves act
// as the rendered text — assertions can match the underlying message keys.
vi.mock("next-intl", () => ({
  useTranslations: () => (key: string) => key,
}));

const toastInfo = vi.fn();
const toastError = vi.fn();
vi.mock("sonner", () => ({
  toast: {
    info: (...args: unknown[]) => toastInfo(...args),
    error: (...args: unknown[]) => toastError(...args),
  },
}));

describe("AiRefinementCard", () => {
  afterEach(() => {
    vi.clearAllMocks();
  });

  it("renders the card with textarea and disabled action buttons by default", () => {
    render(<AiRefinementCard />);

    expect(screen.getByTestId("ai-refinement-card")).toBeInTheDocument();
    expect(screen.getByTestId("ai-refinement-textarea")).toBeInTheDocument();
    expect(screen.getByTestId("ai-refinement-apply")).toBeDisabled();
    expect(screen.getByTestId("ai-refinement-clear")).toBeDisabled();
  });

  it("enables Clear and Apply once the textarea has non-whitespace content", () => {
    render(<AiRefinementCard />);

    const textarea = screen.getByTestId("ai-refinement-textarea");
    fireEvent.change(textarea, { target: { value: "Add Ajaccio" } });

    expect(screen.getByTestId("ai-refinement-clear")).toBeEnabled();
    expect(screen.getByTestId("ai-refinement-apply")).toBeEnabled();
  });

  it("Clear wipes the textarea and re-disables both buttons", () => {
    render(<AiRefinementCard />);

    const textarea = screen.getByTestId(
      "ai-refinement-textarea",
    ) as HTMLTextAreaElement;
    fireEvent.change(textarea, { target: { value: "hello" } });
    fireEvent.click(screen.getByTestId("ai-refinement-clear"));

    expect(textarea.value).toBe("");
    expect(screen.getByTestId("ai-refinement-clear")).toBeDisabled();
    expect(screen.getByTestId("ai-refinement-apply")).toBeDisabled();
  });

  it("surfaces the unavailable toast when no onApply handler is provided", () => {
    render(<AiRefinementCard />);

    const textarea = screen.getByTestId("ai-refinement-textarea");
    fireEvent.change(textarea, { target: { value: "Add Ajaccio" } });
    fireEvent.click(screen.getByTestId("ai-refinement-apply"));

    expect(toastInfo).toHaveBeenCalledWith("unavailable");
  });

  it("clears the textarea when onApply resolves true", async () => {
    const onApply = vi.fn().mockResolvedValue(true);
    render(<AiRefinementCard onApply={onApply} />);

    const textarea = screen.getByTestId(
      "ai-refinement-textarea",
    ) as HTMLTextAreaElement;
    fireEvent.change(textarea, { target: { value: "Add Ajaccio" } });
    fireEvent.click(screen.getByTestId("ai-refinement-apply"));

    await waitFor(() => expect(onApply).toHaveBeenCalledWith("Add Ajaccio"));
    await waitFor(() => expect(textarea.value).toBe(""));
  });

  it("retains the textarea and surfaces error toast when onApply resolves false", async () => {
    const onApply = vi.fn().mockResolvedValue(false);
    render(<AiRefinementCard onApply={onApply} />);

    const textarea = screen.getByTestId(
      "ai-refinement-textarea",
    ) as HTMLTextAreaElement;
    fireEvent.change(textarea, { target: { value: "Add Ajaccio" } });
    fireEvent.click(screen.getByTestId("ai-refinement-apply"));

    await waitFor(() => expect(onApply).toHaveBeenCalled());
    await waitFor(() => expect(toastError).toHaveBeenCalledWith("applyFailed"));
    expect(textarea.value).toBe("Add Ajaccio");
  });

  it("retains the textarea and surfaces error toast when onApply throws", async () => {
    const onApply = vi.fn().mockRejectedValue(new Error("boom"));
    render(<AiRefinementCard onApply={onApply} />);

    const textarea = screen.getByTestId(
      "ai-refinement-textarea",
    ) as HTMLTextAreaElement;
    fireEvent.change(textarea, { target: { value: "Add Ajaccio" } });
    fireEvent.click(screen.getByTestId("ai-refinement-apply"));

    await waitFor(() => expect(toastError).toHaveBeenCalledWith("applyFailed"));
    expect(textarea.value).toBe("Add Ajaccio");
  });

  it("disables both buttons when the disabled prop is true", () => {
    render(<AiRefinementCard disabled />);

    const textarea = screen.getByTestId("ai-refinement-textarea");
    fireEvent.change(textarea, { target: { value: "Add Ajaccio" } });

    expect(screen.getByTestId("ai-refinement-apply")).toBeDisabled();
    expect(screen.getByTestId("ai-refinement-clear")).toBeDisabled();
  });
});
