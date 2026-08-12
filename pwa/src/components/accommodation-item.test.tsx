import { describe, it, expect, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import "@testing-library/jest-dom/vitest";
import { MapPin } from "lucide-react";
import {
  AccommodationItem,
  ACCOMMODATION_TYPE_ICONS,
} from "./accommodation-item";
import { ACCOMMODATION_TYPES } from "@/lib/accommodation-types";
import type { AccommodationData } from "@btp/core";
import fr from "../../messages/fr.json";

// The real French catalogue rather than an identity stub: the completeness
// tests below assert that every contract type resolves to a real label, which
// an identity stub could not detect.
vi.mock("next-intl", async () => {
  const messages = (await import("../../messages/fr.json")).default;
  return {
    useTranslations: (namespace: keyof typeof messages) => (key: string) =>
      (messages[namespace] as Record<string, string>)[key] ??
      `MISSING:${namespace}.${key}`,
  };
});

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

function renderType(type: string) {
  return renderItem(accommodation({ name: "Chez Bernard", type }));
}

/** lucide tags its svg with `lucide-<kebab-name>`, e.g. BedDouble -> bed-double. */
function lucideClass(icon: { displayName?: string }) {
  return `lucide-${icon
    .displayName!.replace(/([a-z0-9])([A-Z])/g, "$1-$2")
    .toLowerCase()}`;
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

    fireEvent.change(screen.getByLabelText(fr.accommodation.urlLabel), {
      target: { value: typed },
    });
    fireEvent.click(screen.getByText(fr.accommodation.save));

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

    const link = screen.getByText(fr.accommodation.see_on_wikipedia);
    expect(link).toHaveAttribute("href", "https://fr.wikipedia.org/wiki/Pont");
  });

  it("renders no link for an unusable Wikipedia value", () => {
    renderItem(accommodation({ wikipediaUrl: "voir wikipedia" }));

    expect(
      screen.queryByText(fr.accommodation.see_on_wikipedia),
    ).not.toBeInTheDocument();
  });
});

describe("AccommodationItem contact and OSM affordances", () => {
  it("renders a clickable tel: link for the phone number", () => {
    renderItem(accommodation({ phone: "+33 4 66 37 82 00" }));

    const link = screen.getByTestId("accommodation-phone-link");
    expect(link).toHaveAttribute("href", "tel:+33 4 66 37 82 00");
    expect(link).toHaveTextContent("+33 4 66 37 82 00");
  });

  it("renders no phone link without a phone number", () => {
    renderItem(accommodation());

    expect(
      screen.queryByTestId("accommodation-phone-link"),
    ).not.toBeInTheDocument();
  });

  it.each([
    ["node", 11] as const,
    ["way", 22] as const,
    ["relation", 33] as const,
  ])("links a %s to its own object on OSM", (osmType, osmId) => {
    renderItem(accommodation({ osmType, osmId }));

    expect(screen.getByTestId("accommodation-osm-link")).toHaveAttribute(
      "href",
      `https://www.openstreetmap.org/${osmType}/${osmId}`,
    );
  });

  it("uses the translated OSM label", () => {
    renderItem(accommodation({ osmType: "node", osmId: 11 }));

    expect(screen.getByTestId("accommodation-osm-link")).toHaveTextContent(
      fr.accommodation.see_on_osm,
    );
  });

  it("renders no OSM link for an entry without an OSM identity", () => {
    renderItem(accommodation({ source: "datatourisme" }));

    expect(
      screen.queryByTestId("accommodation-osm-link"),
    ).not.toBeInTheDocument();
  });

  it("renders no OSM link when only half the key is known", () => {
    renderItem(accommodation({ osmType: "node" }));

    expect(
      screen.queryByTestId("accommodation-osm-link"),
    ).not.toBeInTheDocument();
  });
});

describe("AccommodationItem type rendering", () => {
  it.each(ACCOMMODATION_TYPES)("labels and illustrates a %s", (type) => {
    renderType(type);

    expect(
      screen.getByText(fr.accommodation[`type_${type}`]),
    ).toBeInTheDocument();
    expect(screen.getByTestId("accommodation-type-icon")).toHaveClass(
      lucideClass(ACCOMMODATION_TYPE_ICONS[type]),
    );
  });

  it("renders wilderness_hut as Bivouac, not Autre", () => {
    renderType("wilderness_hut");

    expect(screen.getByText("Bivouac")).toBeInTheDocument();
    expect(screen.queryByText("Autre")).not.toBeInTheDocument();
  });

  it("falls back to Autre for a type outside the contract", () => {
    renderType("igloo");

    expect(screen.getByText("Autre")).toBeInTheDocument();
  });

  it("offers every contract type in the edit form", () => {
    render(
      <AccommodationItem
        accommodation={accommodation()}
        onUpdate={vi.fn()}
        onRemove={vi.fn()}
        initialEditing
      />,
    );

    const select = screen.getByLabelText(fr.accommodation.typeLabel);
    expect([...select.querySelectorAll("option")].map((o) => o.value)).toEqual([
      ...ACCOMMODATION_TYPES,
    ]);
  });
});

describe("ACCOMMODATION_TYPE_ICONS", () => {
  it.each(ACCOMMODATION_TYPES.filter((type) => type !== "other"))(
    "gives %s an icon distinct from the generic fallback",
    (type) => {
      expect(ACCOMMODATION_TYPE_ICONS[type]).not.toBe(MapPin);
    },
  );

  it("covers exactly the contract types", () => {
    expect(Object.keys(ACCOMMODATION_TYPE_ICONS).sort()).toEqual(
      [...ACCOMMODATION_TYPES].sort(),
    );
  });
});
