import { afterEach, describe, expect, it, vi } from "vitest";
import { render, screen, cleanup } from "@testing-library/react";
import "@testing-library/jest-dom/vitest";
import { CardSelection } from "./card-selection";

// Echo translation keys so the component renders without a real catalog.
vi.mock("next-intl", () => ({
  useTranslations: () => (key: string) => key,
}));

vi.mock("next/link", () => ({
  default: ({
    href,
    children,
    ...rest
  }: {
    href: string;
    children: React.ReactNode;
  }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}));

const noop = () => {};

afterEach(() => {
  cleanup();
});

describe("CardSelection", () => {
  it("shows the link and GPX cards", () => {
    render(<CardSelection onSubmitUrl={noop} onUploadFile={noop} />);
    expect(screen.getByTestId("card-link")).toBeInTheDocument();
    expect(screen.getByTestId("card-gpx")).toBeInTheDocument();
    expect(screen.queryByTestId("card-ai")).not.toBeInTheDocument();
  });
});
