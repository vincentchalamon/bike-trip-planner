import { describe, expect, it } from "vitest";
import { isSupportedSourceUrl } from "./url";

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
