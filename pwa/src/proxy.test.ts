import { describe, it, expect, afterEach, vi } from "vitest";
import { NextRequest } from "next/server";
import { config, proxy } from "./proxy";

function nonceOf(policy: string | null): string | undefined {
  return policy?.match(/'nonce-([^']+)'/)?.[1];
}

describe("proxy", () => {
  afterEach(() => vi.unstubAllEnvs());

  it("sends the same nonce policy to the renderer and to the browser", () => {
    const response = proxy(new NextRequest("https://localhost/trips/new"));

    const sent = response.headers.get("Content-Security-Policy");
    // NextResponse.next() forwards overridden request headers this way.
    const rendered = response.headers.get(
      "x-middleware-request-content-security-policy",
    );
    const nonce = response.headers.get("x-middleware-request-x-nonce");

    expect(nonceOf(sent)).toBeDefined();
    expect(rendered).toBe(sent);
    expect(nonce).toBe(nonceOf(sent));
  });

  it("draws a new nonce for every document", () => {
    const first = proxy(new NextRequest("https://localhost/"));
    const second = proxy(new NextRequest("https://localhost/"));

    expect(nonceOf(first.headers.get("Content-Security-Policy"))).not.toBe(
      nonceOf(second.headers.get("Content-Security-Policy")),
    );
  });

  it("points violation reports at CSP_REPORT_URI when set", () => {
    vi.stubEnv("CSP_REPORT_URI", "https://glitchtip.example/api/1/security/");

    const response = proxy(new NextRequest("https://localhost/"));

    expect(response.headers.get("Content-Security-Policy")).toContain(
      "report-uri https://glitchtip.example/api/1/security/",
    );
  });

  it("only matches documents", () => {
    const source = new RegExp(`^${config.matcher[0]!.source}$`);

    for (const path of ["/", "/trips/new", "/s/Ab3x9Z", "/oauth/consent/h"]) {
      expect(source.test(path), path).toBe(true);
    }
    for (const path of [
      "/api/auth/refresh",
      "/_next/static/chunks/main.js",
      "/vendor/maplibre/maplibre-gl-worker.mjs",
      "/favicon.ico",
      "/.well-known/assetlinks.json",
    ]) {
      expect(source.test(path), path).toBe(false);
    }
  });
});
