import { describe, it, expect, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import "@testing-library/jest-dom/vitest";
import { AccommodationItem } from "./accommodation-item";
import type { AccommodationData } from "@/lib/validation/schemas";

vi.mock("next-intl", () => ({
  useTranslations: () => (key: string) => key,
}));

function accommodation(
  overrides: Partial<AccommodationData> = {},
): AccommodationData {
  return {
    name: "Hotel du Pont",
    type: "hotel",
    lat: 44.5,
    lon: 4.38,
    estimatedPriceMin: 65,
    estimatedPriceMax: 85,
    isExactPrice: false,
    possibleClosed: false,
    distanceToEndPoint: 0.5,
    source: "osm",
    ...overrides,
  };
}

function renderItem(data: AccommodationData, onUpdate = vi.fn()) {
  return {
    onUpdate,
    ...render(
      <AccommodationItem
        accommodation={data}
        onUpdate={onUpdate}
        onRemove={vi.fn()}
      />,
    ),
  };
}

describe("AccommodationItem website link", () => {
  it("renders an absolute link for a schemeless OSM website tag", () => {
    renderItem(accommodation({ url: "www.hotel.fr" }));

    const link = screen.getByTestId("accommodation-website-link");
    expect(link).toHaveAttribute("href", "https://www.hotel.fr");
    expect(link).toHaveTextContent("www.hotel.fr");
  });

  it("keeps an already absolute https URL untouched", () => {
    renderItem(accommodation({ url: "https://www.hotel.fr/chambres" }));

    expect(screen.getByTestId("accommodation-website-link")).toHaveAttribute(
      "href",
      "https://www.hotel.fr/chambres",
    );
  });

  it.each(["appeler le 06 12 34 56 78", "mailto:contact@hotel.fr", "   "])(
    "renders no link and does not throw for %s",
    (url) => {
      expect(() => renderItem(accommodation({ url }))).not.toThrow();
      expect(
        screen.queryByTestId("accommodation-website-link"),
      ).not.toBeInTheDocument();
    },
  );
});

describe("AccommodationItem edits", () => {
  function editAndSave(typed: string) {
    const onUpdate = vi.fn();
    render(
      <AccommodationItem
        accommodation={accommodation()}
        onUpdate={onUpdate}
        onRemove={vi.fn()}
        initialEditing
      />,
    );

    fireEvent.change(screen.getByLabelText("urlLabel"), {
      target: { value: typed },
    });
    fireEvent.click(screen.getByText("save"));

    return onUpdate;
  }

  it("normalizes a schemeless URL typed by the user before saving", () => {
    expect(editAndSave("www.hotel.fr")).toHaveBeenCalledWith(
      expect.objectContaining({ url: "https://www.hotel.fr" }),
    );
  });

  it("saves null when the typed URL is not usable", () => {
    expect(editAndSave("je ne sais pas")).toHaveBeenCalledWith(
      expect.objectContaining({ url: null }),
    );
  });
});

describe("AccommodationItem Wikipedia link", () => {
  it("uses the translated label", () => {
    renderItem(
      accommodation({ wikipediaUrl: "https://fr.wikipedia.org/wiki/Pont" }),
    );

    const link = screen.getByText("see_on_wikipedia");
    expect(link).toHaveAttribute("href", "https://fr.wikipedia.org/wiki/Pont");
  });

  it("renders no link for an unusable Wikipedia value", () => {
    renderItem(accommodation({ wikipediaUrl: "voir wikipedia" }));

    expect(screen.queryByText("see_on_wikipedia")).not.toBeInTheDocument();
  });
});
