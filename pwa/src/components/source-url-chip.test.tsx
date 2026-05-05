import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import "@testing-library/jest-dom/vitest";
import { SourceUrlChip, detectSourceProvider } from "./source-url-chip";

vi.mock("next-intl", () => ({
  useTranslations: () => (key: string) => key,
}));

describe("detectSourceProvider", () => {
  it.each([
    ["https://www.komoot.com/tour/12345", "komoot"],
    ["https://www.komoot.com/fr-fr/tour/12345", "komoot"],
    ["https://www.komoot.com/collection/12345", "komoot"],
    ["https://www.komoot.com/en-us/collection/678", "komoot"],
    ["https://www.strava.com/routes/9876", "strava"],
    ["https://ridewithgps.com/routes/42", "ridewithgps"],
  ])("detects %s as %s", (url, expected) => {
    expect(detectSourceProvider(url)).toBe(expected);
  });

  it.each([
    // Backend RouteFetcherRegistry requires strict host (no http://, www only
    // for komoot/strava). Permissive client-side regexes would create
    // false-positive chips.
    ["https://strava.com/routes/9876"],
    ["https://www.ridewithgps.com/routes/42"],
    ["http://www.komoot.com/tour/12345"],
  ])("treats %s as unsupported (mirrors backend strictness)", (url) => {
    expect(detectSourceProvider(url)).toBe("unsupported");
  });

  it("returns 'unsupported' for valid URLs that don't match any provider", () => {
    expect(detectSourceProvider("https://example.com/route/1")).toBe(
      "unsupported",
    );
  });

  it("returns null for empty values", () => {
    expect(detectSourceProvider("")).toBeNull();
    expect(detectSourceProvider("   ")).toBeNull();
  });

  it("returns null for invalid URLs", () => {
    expect(detectSourceProvider("not-a-url")).toBeNull();
    expect(detectSourceProvider("foo bar")).toBeNull();
  });
});

describe("SourceUrlChip", () => {
  it("renders nothing for empty input", () => {
    const { container } = render(<SourceUrlChip value="" />);
    expect(container.firstChild).toBeNull();
  });

  it("renders nothing for invalid URLs", () => {
    const { container } = render(<SourceUrlChip value="not-a-url" />);
    expect(container.firstChild).toBeNull();
  });

  it("renders Komoot chip for a Komoot tour URL", () => {
    render(<SourceUrlChip value="https://www.komoot.com/tour/12345" />);
    const chip = screen.getByTestId("source-url-chip");
    expect(chip).toHaveAttribute("data-provider", "komoot");
    expect(chip).toHaveTextContent("komoot");
  });

  it("renders Strava chip for a Strava route URL", () => {
    render(<SourceUrlChip value="https://www.strava.com/routes/9876" />);
    expect(screen.getByTestId("source-url-chip")).toHaveAttribute(
      "data-provider",
      "strava",
    );
  });

  it("renders RideWithGPS chip for a RWGPS URL", () => {
    render(<SourceUrlChip value="https://ridewithgps.com/routes/42" />);
    expect(screen.getByTestId("source-url-chip")).toHaveAttribute(
      "data-provider",
      "ridewithgps",
    );
  });

  it("renders unsupported chip for an unrecognised valid URL", () => {
    render(<SourceUrlChip value="https://example.com/foo" />);
    expect(screen.getByTestId("source-url-chip")).toHaveAttribute(
      "data-provider",
      "unsupported",
    );
  });
});
