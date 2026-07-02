import { afterEach, describe, expect, it, vi } from "vitest";
import { parseJwtPayload, useAuthStore } from "./auth-store";

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

describe("setUserEmail", () => {
  afterEach(() => {
    useAuthStore.getState().clearAuth();
  });

  it("updates the current user's email after an email change (#777)", () => {
    useAuthStore
      .getState()
      .setAuth("token", { id: "u1", email: "old@example.com" });

    useAuthStore.getState().setUserEmail("new@example.com");

    expect(useAuthStore.getState().user?.email).toBe("new@example.com");
  });

  it("is a no-op when no user is set", () => {
    useAuthStore.getState().setUserEmail("new@example.com");

    expect(useAuthStore.getState().user).toBeNull();
  });
});

describe("ensureResolved (recette #649 #8)", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.resetModules();
  });

  it("runs a one-time bootstrap refresh, then is a no-op", async () => {
    const token = buildJwt({ sub: "u1", username: "rider@example.com" });
    const fetchMock = vi.fn(
      async () =>
        new Response(JSON.stringify({ token }), {
          status: 200,
          headers: { "Content-Type": "application/json" },
        }),
    );
    vi.stubGlobal("fetch", fetchMock);
    // Fresh module so the one-shot `authChecked` flag starts unset.
    vi.resetModules();
    const { useAuthStore: store } = await import("./auth-store");

    await store.getState().ensureResolved();
    expect(store.getState().isAuthenticated).toBe(true);
    expect(fetchMock).toHaveBeenCalledTimes(1);

    // Already settled → no second /auth/refresh.
    await store.getState().ensureResolved();
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });
});
