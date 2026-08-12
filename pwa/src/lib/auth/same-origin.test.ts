import { describe, expect, it } from "vitest";
import { enforceSameOrigin } from "./same-origin";

function requestWith(headers: Record<string, string>): Request {
  return new Request("https://app.example.com/api/auth/refresh", { headers });
}

describe("enforceSameOrigin", () => {
  it("allows a same-origin fetch-metadata request", () => {
    expect(
      enforceSameOrigin(requestWith({ "sec-fetch-site": "same-origin" })),
    ).toBeNull();
  });

  it.each(["same-site", "cross-site", "none"])(
    "rejects Sec-Fetch-Site: %s with 403",
    (value) => {
      const denied = enforceSameOrigin(
        requestWith({ "sec-fetch-site": value }),
      );
      expect(denied).not.toBeNull();
      expect(denied?.status).toBe(403);
    },
  );

  it("falls back to Origin when Sec-Fetch-Site is absent (matching origin allowed)", () => {
    expect(
      enforceSameOrigin(requestWith({ origin: "https://app.example.com" })),
    ).toBeNull();
  });

  it("rejects a mismatched Origin with 403 when Sec-Fetch-Site is absent", () => {
    const denied = enforceSameOrigin(
      requestWith({ origin: "https://evil.example.com" }),
    );
    expect(denied).not.toBeNull();
    expect(denied?.status).toBe(403);
  });

  it("rejects a request with neither Sec-Fetch-Site nor Origin", () => {
    const denied = enforceSameOrigin(requestWith({}));
    expect(denied).not.toBeNull();
    expect(denied?.status).toBe(403);
  });
});
