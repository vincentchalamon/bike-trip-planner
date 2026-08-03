import { describe, expect, it } from "vitest";
import {
  externalUrlHostname,
  isSupportedSourceUrl,
  normalizeExternalUrl,
} from "./url";

describe("isSupportedSourceUrl", () => {
  it.each([
    "https://www.komoot.com/tour/123",
    "https://www.komoot.com/fr-fr/tour/123",
    "https://www.komoot.com/collection/456",
    "https://www.komoot.com/fr-fr/collection/456",
    "https://www.strava.com/routes/789",
    "https://ridewithgps.com/routes/101",
  ])("accepts %s", (url) => expect(isSupportedSourceUrl(url)).toBe(true));

  it.each([
    "https://example.com/route/1",
    "http://www.komoot.com/tour/123",
    "https://www.komoot.com/tour/",
    "",
  ])("rejects %s", (url) => expect(isSupportedSourceUrl(url)).toBe(false));
});

describe("normalizeExternalUrl", () => {
  it.each([
    ["www.hotel.fr", "https://www.hotel.fr"],
    ["hotel.fr", "https://hotel.fr"],
    ["hotel.fr/chambres?lang=fr", "https://hotel.fr/chambres?lang=fr"],
    ["www.hotel.fr:8080/reserver", "https://www.hotel.fr:8080/reserver"],
    ["  www.hotel.fr  ", "https://www.hotel.fr"],
  ])("prefixes the missing scheme: %s", (value, expected) =>
    expect(normalizeExternalUrl(value)).toBe(expected),
  );

  it.each([
    "https://www.hotel.fr",
    "https://www.hotel.fr/chambres",
    "http://hotel.fr",
  ])("leaves %s untouched", (value) =>
    expect(normalizeExternalUrl(value)).toBe(value),
  );

  it.each([
    ["mailto:contact@hotel.fr"],
    ["javascript:alert(1)"],
    ["data:text/html,<script>alert(1)</script>"],
    ["ftp://hotel.fr"],
    ["tel:+33123456789"],
    ["appeler le 06 12 34 56 78"],
    ["Hôtel"],
    ["localhost"],
    [""],
    ["   "],
    [null],
    [undefined],
  ])("rejects %s", (value) => expect(normalizeExternalUrl(value)).toBeNull());
});

describe("externalUrlHostname", () => {
  it("returns the hostname of a schemeless value", () =>
    expect(externalUrlHostname("www.hotel.fr/chambres")).toBe("www.hotel.fr"));

  it("returns null when the value is unusable", () =>
    expect(externalUrlHostname("mailto:contact@hotel.fr")).toBeNull());
});
