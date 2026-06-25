import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import "@testing-library/jest-dom/vitest";
import { SharedTopBar } from "./shared-top-bar";

vi.mock("next-intl", () => ({
  useTranslations: () => (key: string) => key,
}));

vi.mock("next/link", () => ({
  default: ({
    children,
    ...props
  }: {
    children: React.ReactNode;
    href: string;
  }) => <a {...props}>{children}</a>,
}));

describe("SharedTopBar", () => {
  it("renders GPX/FIT downloads by default", () => {
    render(<SharedTopBar tripTitle="My trip" />);
    expect(screen.getByTestId("trip-download-gpx")).toBeInTheDocument();
    expect(screen.getByTestId("trip-download-fit")).toBeInTheDocument();
  });

  it("hides GPX/FIT downloads on a revoked/404 share (isError)", () => {
    render(<SharedTopBar isError />);
    expect(screen.queryByTestId("trip-download-gpx")).not.toBeInTheDocument();
    expect(screen.queryByTestId("trip-download-fit")).not.toBeInTheDocument();
  });
});
