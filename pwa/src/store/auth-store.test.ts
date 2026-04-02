import { describe, expect, it } from "vitest";
import { parseJwtPayload } from "./auth-store";

// Helper: build a JWT with a given payload (no signature verification needed)
function buildJwt(payload: Record<string, unknown>): string {
  const header = btoa(JSON.stringify({ alg: "HS256", typ: "JWT" }));
  const body = btoa(JSON.stringify(payload))
    .replace(/\+/g, "-")
    .replace(/\//g, "_")
    .replace(/=+$/, "");
  return `${header}.${body}.fake-signature`;
}

describe("parseJwtPayload", () => {
  it("extracts sub and email from a valid JWT", () => {
    const token = buildJwt({
      sub: "550e8400-e29b-41d4-a716-446655440000",
      username: "alice@example.com",
    });
    expect(parseJwtPayload(token)).toEqual({
      sub: "550e8400-e29b-41d4-a716-446655440000",
      email: "alice@example.com",
    });
  });

  it("returns null when sub is missing", () => {
    const token = buildJwt({ username: "alice@example.com" });
    expect(parseJwtPayload(token)).toBeNull();
  });

  it("returns null when username is missing", () => {
    const token = buildJwt({ sub: "some-uuid" });
    expect(parseJwtPayload(token)).toBeNull();
  });

  it("returns null for a token with fewer than 3 parts", () => {
    expect(parseJwtPayload("only.two")).toBeNull();
  });

  it("returns null for a token with more than 3 parts", () => {
    expect(parseJwtPayload("a.b.c.d")).toBeNull();
  });

  it("returns null for an empty string", () => {
    expect(parseJwtPayload("")).toBeNull();
  });

  it("returns null when payload is not valid JSON", () => {
    expect(parseJwtPayload("header.!!!.signature")).toBeNull();
  });

  it("handles base64url padding correctly", () => {
    // Payload with characters that need base64url encoding
    const token = buildJwt({
      sub: "uuid-with-special/chars+here",
      username: "user@example.com",
    });
    expect(parseJwtPayload(token)).toEqual({
      sub: "uuid-with-special/chars+here",
      email: "user@example.com",
    });
  });
});
