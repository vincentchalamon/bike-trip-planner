import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import "@testing-library/jest-dom/vitest";
import { StageCycleNetworkBadge } from "./stage-cycle-network-badge";

vi.mock("next-intl", () => ({
  useTranslations: () => (key: string, params?: Record<string, number>) =>
    params ? `${key}:${JSON.stringify(params)}` : key,
}));

describe("StageCycleNetworkBadge", () => {
  it("renders the badge with a rounded percentage when mostly on network", () => {
    render(<StageCycleNetworkBadge fraction={0.82} />);

    const badge = screen.getByTestId("cycle-network-badge");
    expect(badge).toBeInTheDocument();
    expect(badge).toHaveTextContent('badge:{"percent":82}');
  });

  it("renders at the 0.5 threshold", () => {
    render(<StageCycleNetworkBadge fraction={0.5} />);

    expect(screen.getByTestId("cycle-network-badge")).toBeInTheDocument();
  });

  it("renders nothing below the threshold", () => {
    render(<StageCycleNetworkBadge fraction={0.49} />);

    expect(screen.queryByTestId("cycle-network-badge")).not.toBeInTheDocument();
  });

  it("renders nothing for a stage with no cycle-route overlap", () => {
    render(<StageCycleNetworkBadge fraction={0} />);

    expect(screen.queryByTestId("cycle-network-badge")).not.toBeInTheDocument();
  });
});
