import { test, expect } from "@playwright/test";

// Regression for the mobile mojibake fix (PR #1161, device #1090).
//
// The edge (Caddy) must compress JSON(-LD) for normal web/PWA clients — which
// decode zstd/br fine — but serve it UNCOMPRESSED to native mobile clients, which
// advertise `zstd, br, gzip` (RN/okhttp) yet only transparently decode gzip they
// added themselves. A zstd/br body reaches the app raw and is read as Latin-1, so
// UTF-8 `é` (C3 A9) becomes "Ã©" and accented trip titles mojibake. Mobile signals
// itself with `X-Client-Platform: mobile`; Caddy rewrites that request's
// Accept-Encoding to `identity` before `encode` runs.
//
// This test runs in the Playwright CI job, which boots the real compose stack and
// runs with `--network host` against `https://localhost` — i.e. the actual Caddy
// edge (ADR-037). That is what makes it a real regression test: the app-layer
// PHPUnit guard goes through Symfony's test client and bypasses Caddy entirely, so
// it cannot see a compression-negotiation change. It fails before the fix (JSON was
// dropped from `encode`, so web clients also got uncompressed bodies) and passes
// after.
test.describe("Edge compression negotiation", () => {
  // API Platform Hydra documentation: PUBLIC_ACCESS (`^/docs` in security.php, no
  // auth — the API entrypoint `/` is behind the firewall and 401s) and ~68KB of
  // ld+json, well above Caddy's ~512-byte compression floor. The `.jsonld` suffix
  // pins the format so Caddy never routes it to the PWA.
  const ENTRYPOINT = "/docs.jsonld";
  const LDJSON = "application/ld+json";
  const NATIVE_ADVERTISED = "zstd, br, gzip";

  test("compresses JSON-LD for a normal web client", async ({ request }) => {
    const res = await request.get(ENTRYPOINT, {
      headers: { Accept: LDJSON, "Accept-Encoding": NATIVE_ADVERTISED },
    });

    expect(res.status()).toBe(200);
    expect(res.headers()["content-type"]).toContain(LDJSON);
    // The edge negotiated a real compression algorithm for the web client.
    expect(res.headers()["content-encoding"]).toMatch(/^(zstd|br|gzip)$/);
  });

  test("serves JSON-LD uncompressed to a native mobile client", async ({
    request,
  }) => {
    const res = await request.get(ENTRYPOINT, {
      headers: {
        Accept: LDJSON,
        "Accept-Encoding": NATIVE_ADVERTISED,
        "X-Client-Platform": "mobile",
      },
    });

    expect(res.status()).toBe(200);
    expect(res.headers()["content-type"]).toContain(LDJSON);
    // No compression header at all: the mobile opt-out disabled `encode`. Reading
    // the (identity) body also confirms the payload really is above Caddy's ~512B
    // floor, so the "uncompressed" result means the edge declined, not a tiny body.
    expect(res.headers()["content-encoding"]).toBeUndefined();
    expect((await res.body()).byteLength).toBeGreaterThan(512);
  });
});
