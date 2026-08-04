import { describe, it, expect } from "vitest";
import fr from "../../messages/fr.json";
import en from "../../messages/en.json";
import {
  ACCOMMODATION_TYPES,
  DEFAULT_ACCOMMODATION_TYPES,
  FILTERABLE_ACCOMMODATION_TYPES,
  accommodationTypeLabelKey,
  isAccommodationType,
} from "./accommodation-types";

const catalogs: Record<string, Record<string, string>> = {
  fr: fr.accommodation,
  en: en.accommodation,
};

describe("accommodation type catalog", () => {
  // Guard against the #866 regression: a type present in the contract but
  // missing from a message catalog used to render silently as "Autre".
  it.each(Object.keys(catalogs))(
    "translates every contract type in %s.json",
    (locale) => {
      const catalog = catalogs[locale]!;
      const missing = ACCOMMODATION_TYPES.filter(
        (type) => !catalog[accommodationTypeLabelKey(type)],
      );

      expect(missing).toEqual([]);
    },
  );

  it.each([...FILTERABLE_ACCOMMODATION_TYPES, ...DEFAULT_ACCOMMODATION_TYPES])(
    "keeps %s in the full type list",
    (type) => {
      expect(ACCOMMODATION_TYPES).toContain(type);
    },
  );

  it("maps each contract type to its own key", () => {
    expect(accommodationTypeLabelKey("camp_site")).toBe("type_camp_site");
    expect(accommodationTypeLabelKey("wilderness_hut")).toBe(
      "type_wilderness_hut",
    );
  });

  // #927: they left the vocabulary, so they must render as "Autre" rather than
  // keep a label of their own — and no catalog entry may linger for them.
  it.each(["shelter", "motel", "rental"])(
    "treats the removed type %s as outside the contract",
    (type) => {
      expect(isAccommodationType(type)).toBe(false);
      expect(accommodationTypeLabelKey(type)).toBe("type_other");
      for (const catalog of Object.values(catalogs)) {
        expect(catalog[`type_${type}`]).toBeUndefined();
      }
    },
  );

  it("falls back to type_other for a type outside the contract", () => {
    expect(isAccommodationType("igloo")).toBe(false);
    expect(accommodationTypeLabelKey("igloo")).toBe("type_other");
  });
});
