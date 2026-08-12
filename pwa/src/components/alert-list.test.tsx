import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import "@testing-library/jest-dom/vitest";
import { AlertList } from "./alert-list";
import type { AlertData } from "@btp/core";

vi.mock("next-intl", () => ({
  useTranslations: () => (key: string) => key,
}));

/**
 * A cultural-POI alert, the only shape that renders the Wikipedia link.
 * `wikipediaUrl` reaches this component through the unvalidated
 * `JSON.parse(event.data) as MercureEvent` cast, so it can be anything.
 */
function culturalPoiAlert(overrides: Partial<AlertData> = {}): AlertData {
  return {
    type: "nudge",
    message: "Pont du Gard à 2 km",
    source: "cultural_poi",
    poiName: "Pont du Gard",
    poiLat: 43.947,
    poiLon: 4.535,
    description: "Aqueduc romain",
    ...overrides,
  };
}

describe("AlertList Wikipedia link", () => {
  it("renders an absolute link with the translated label for a usable url", () => {
    render(
      <AlertList
        alerts={[
          culturalPoiAlert({
            wikipediaUrl: "https://fr.wikipedia.org/wiki/Pont_du_Gard",
          }),
        ]}
      />,
    );

    const link = screen.getByTestId("poi-wikipedia-link");
    expect(link).toHaveAttribute(
      "href",
      "https://fr.wikipedia.org/wiki/Pont_du_Gard",
    );
    expect(link).toHaveTextContent("see_on_wikipedia");
  });

  it("normalizes a schemeless url into an absolute link", () => {
    render(
      <AlertList
        alerts={[
          culturalPoiAlert({ wikipediaUrl: "fr.wikipedia.org/wiki/Nimes" }),
        ]}
      />,
    );

    expect(screen.getByTestId("poi-wikipedia-link")).toHaveAttribute(
      "href",
      "https://fr.wikipedia.org/wiki/Nimes",
    );
  });

  it.each(["javascript:alert(1)", "mailto:contact@wikipedia.org", "   "])(
    "renders no link and does not throw for %j",
    (wikipediaUrl) => {
      expect(() =>
        render(<AlertList alerts={[culturalPoiAlert({ wikipediaUrl })]} />),
      ).not.toThrow();

      expect(
        screen.queryByTestId("poi-wikipedia-link"),
      ).not.toBeInTheDocument();
    },
  );
});
