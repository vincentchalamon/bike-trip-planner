import { describe, it, expect } from "vitest";
import { importEventForUrl } from "@/hooks/use-trip-planner";

describe("importEventForUrl", () => {
  it.each([
    ["https://www.komoot.com/tour/123", "import_komoot"],
    ["https://www.strava.com/routes/456", "import_strava"],
    ["https://ridewithgps.com/routes/789", "import_rwgps"],
    ["https://example.com/gpx", null],
  ])("%s → %s", (url, expected) => {
    expect(importEventForUrl(url)).toBe(expected);
  });
});
