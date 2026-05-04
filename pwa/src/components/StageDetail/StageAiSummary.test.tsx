import { render, act } from "@testing-library/react";
import { describe, it, expect, vi } from "vitest";
import "@testing-library/jest-dom/vitest";
import { StageAiSummary } from "./StageAiSummary";

// Stub next-intl translations: return the key suffix so we don't need a
// NextIntlClientProvider in this unit test.
vi.mock("next-intl", () => ({
  useTranslations: () => (key: string) => key,
}));

describe("StageAiSummary", () => {
  it("collapses when summary grows past threshold after mount (late SSE delivery)", () => {
    const { rerender, queryByTestId, getByTestId } = render(
      <StageAiSummary summary="short" longThreshold={50} />,
    );

    // Initially short — no toggle rendered, text rendered as expanded.
    expect(queryByTestId("stage-ai-summary-toggle")).toBeNull();
    expect(getByTestId("stage-ai-summary-text")).toHaveAttribute(
      "data-expanded",
      "true",
    );

    // SSE delivers a long summary that crosses the threshold.
    act(() => {
      rerender(<StageAiSummary summary={"x".repeat(60)} longThreshold={50} />);
    });

    // Toggle now visible and text is clamped (expanded = false), proving the
    // prevIsLong guard re-syncs the derived state on prop change.
    const toggle = getByTestId("stage-ai-summary-toggle");
    expect(toggle).toBeVisible();
    expect(toggle).toHaveAttribute("aria-expanded", "false");
    expect(getByTestId("stage-ai-summary-text")).toHaveAttribute(
      "data-expanded",
      "false",
    );
  });

  it("keeps short summary expanded with no toggle", () => {
    const { queryByTestId, getByTestId } = render(
      <StageAiSummary summary="hello" longThreshold={50} />,
    );
    expect(queryByTestId("stage-ai-summary-toggle")).toBeNull();
    expect(getByTestId("stage-ai-summary-text")).toHaveAttribute(
      "data-expanded",
      "true",
    );
  });
});
