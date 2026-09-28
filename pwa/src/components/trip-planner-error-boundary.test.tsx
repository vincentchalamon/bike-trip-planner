import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import "@testing-library/jest-dom/vitest";
import { TripPlannerErrorBoundary } from "./trip-planner-error-boundary";

vi.mock("next-intl", () => ({
  useTranslations: (namespace: string) => (key: string) =>
    `${namespace}.${key}`,
}));
vi.mock("@/lib/logger", () => ({ logger: { error: vi.fn() } }));

function Boom(): never {
  throw new Error("boom");
}

describe("TripPlannerErrorBoundary", () => {
  it("renders the translated error card", () => {
    vi.spyOn(console, "error").mockImplementation(() => {});

    render(
      <TripPlannerErrorBoundary>
        <Boom />
      </TripPlannerErrorBoundary>,
    );

    expect(screen.getByTestId("error-title")).toHaveTextContent(
      "errorPages.error.title",
    );
    expect(screen.getByTestId("error-retry-button")).toHaveTextContent(
      "errorPages.error.retry",
    );
  });
});
