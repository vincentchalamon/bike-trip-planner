import { afterEach, describe, expect, it, vi } from "vitest";
import { GET } from "./route";

describe("assetlinks.json route", () => {
  afterEach(() => {
    vi.unstubAllEnvs();
  });

  async function payload() {
    return GET().json();
  }

  it("returns an empty statement list when both env vars are unset", async () => {
    vi.stubEnv("ANDROID_APP_PACKAGE", "");
    vi.stubEnv("ANDROID_SHA256_CERT_FINGERPRINTS", "");
    expect(await payload()).toEqual([]);
  });

  it("returns [] when only the package is set", async () => {
    vi.stubEnv("ANDROID_APP_PACKAGE", "coop.lestilleuls.biketripplanner");
    vi.stubEnv("ANDROID_SHA256_CERT_FINGERPRINTS", "");
    expect(await payload()).toEqual([]);
  });

  it("returns [] when only fingerprints are set", async () => {
    vi.stubEnv("ANDROID_APP_PACKAGE", "");
    vi.stubEnv("ANDROID_SHA256_CERT_FINGERPRINTS", "AA:BB");
    expect(await payload()).toEqual([]);
  });

  it("returns the statement list with trimmed fingerprints when both are set", async () => {
    vi.stubEnv("ANDROID_APP_PACKAGE", "coop.lestilleuls.biketripplanner");
    vi.stubEnv("ANDROID_SHA256_CERT_FINGERPRINTS", " AA:BB , CC:DD ");
    expect(await payload()).toEqual([
      {
        relation: ["delegate_permission/common.handle_all_urls"],
        target: {
          namespace: "android_app",
          package_name: "coop.lestilleuls.biketripplanner",
          sha256_cert_fingerprints: ["AA:BB", "CC:DD"],
        },
      },
    ]);
  });
});
