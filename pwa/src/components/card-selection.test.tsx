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
  vi.unstubAllEnvs();
});

// Vitest sets NEXT_PUBLIC_ENABLE_AI=true globally (vitest.config.ts); the masked
// case stubs it off to prove the AI surface disappears (recette #649).
describe("CardSelection — AI feature flag (recette #649)", () => {
  it("shows the AI assistant card when the flag is on", () => {
    render(<CardSelection onSubmitUrl={noop} onUploadFile={noop} />);
    expect(screen.getByTestId("card-ai")).toBeInTheDocument();
    expect(screen.getByTestId("card-link")).toBeInTheDocument();
    expect(screen.getByTestId("card-gpx")).toBeInTheDocument();
  });

  it("hides the AI assistant card when the flag is off (link + GPX remain)", () => {
    vi.stubEnv("NEXT_PUBLIC_ENABLE_AI", "false");
    render(<CardSelection onSubmitUrl={noop} onUploadFile={noop} />);
    expect(screen.queryByTestId("card-ai")).not.toBeInTheDocument();
    expect(screen.getByTestId("card-link")).toBeInTheDocument();
    expect(screen.getByTestId("card-gpx")).toBeInTheDocument();
  });
});
