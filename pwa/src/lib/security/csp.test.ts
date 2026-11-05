import { describe, it, expect } from "vitest";
import { buildContentSecurityPolicy, generateNonce } from "./csp";

function directive(policy: string, name: string): string | undefined {
  return policy
    .split(";")
    .map((d) => d.trim())
    .find((d) => d === name || d.startsWith(`${name} `));
}

describe("buildContentSecurityPolicy", () => {
  it("only runs scripts carrying the nonce in production", () => {
    const policy = buildContentSecurityPolicy({ nonce: "abc", isDev: false });

    expect(directive(policy, "script-src")).toBe(
      "script-src 'self' 'nonce-abc' 'strict-dynamic'",
    );
  });

  it("never allows inline or eval'd scripts in production", () => {
    const script = directive(
      buildContentSecurityPolicy({ nonce: "abc", isDev: false }),
      "script-src",
    );

    expect(script).not.toContain("'unsafe-inline'");
    expect(script).not.toContain("'unsafe-eval'");
  });

  it("allows eval in development only, for the React dev build", () => {
    const script = directive(
      buildContentSecurityPolicy({ nonce: "abc", isDev: true }),
      "script-src",
    );

    expect(script).toContain("'unsafe-eval'");
    expect(script).not.toContain("'unsafe-inline'");
  });

  /** A nonce in style-src would make the browser ignore 'unsafe-inline'. */
  it("keeps inline styles without a style nonce", () => {
    expect(
      directive(
        buildContentSecurityPolicy({ nonce: "abc", isDev: false }),
        "style-src",
      ),
    ).toBe("style-src 'self' 'unsafe-inline'");
  });

  it("keeps the non-fetch directives and the MapLibre workers", () => {
    const policy = buildContentSecurityPolicy({ nonce: "abc", isDev: false });

    expect(directive(policy, "object-src")).toBe("object-src 'none'");
    expect(directive(policy, "frame-ancestors")).toBe("frame-ancestors 'none'");
    expect(directive(policy, "base-uri")).toBe("base-uri 'self'");
    expect(directive(policy, "form-action")).toBe("form-action 'self'");
    expect(directive(policy, "worker-src")).toBe("worker-src 'self' blob:");
  });

  it("adds the origin, and only the origin, of the configured services", () => {
    const connect = directive(
      buildContentSecurityPolicy({
        nonce: "abc",
        isDev: false,
        connectUrls: [
          "https://key@glitchtip.example/12",
          "https://analytics.example/js/script.js",
          undefined,
          "",
          "not a url",
        ],
      }),
      "connect-src",
    );

    expect(connect).toContain("'self'");
    expect(connect).toContain("https://*.basemaps.cartocdn.com");
    expect(connect).toContain("https://glitchtip.example");
    expect(connect).toContain("https://analytics.example");
    expect(connect).not.toContain("/js/script.js");
    expect(connect).not.toContain("key@");
    expect(connect).not.toContain("not a url");
  });

  it("reports violations only when a report URI is configured", () => {
    expect(
      directive(
        buildContentSecurityPolicy({ nonce: "abc", isDev: false }),
        "report-uri",
      ),
    ).toBeUndefined();
    expect(
      directive(
        buildContentSecurityPolicy({
          nonce: "abc",
          isDev: false,
          reportUri: "https://glitchtip.example/api/1/security/",
        }),
        "report-uri",
      ),
    ).toBe("report-uri https://glitchtip.example/api/1/security/");
  });
});

describe("generateNonce", () => {
  it("is fresh on every call and safe inside a CSP source", () => {
    const a = generateNonce();
    const b = generateNonce();

    expect(a).not.toBe(b);
    expect(a).toMatch(/^[A-Za-z0-9+/]{22}==$/);
  });
});
