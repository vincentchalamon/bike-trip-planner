import { afterEach, describe, expect, it, vi } from "vitest";
import { cookies } from "next/headers";
import {
  clearLoginNonce,
  ensureLoginNonce,
  readLoginNonce,
} from "./login-nonce";

vi.mock("next/headers", () => ({ cookies: vi.fn() }));

const mockedCookies = vi.mocked(cookies);

function stubCookieJar(existing?: string) {
  const jar = {
    set: vi.fn(),
    get: vi.fn((name: string) =>
      name === "__Host-login_nonce" && existing !== undefined
        ? { name, value: existing }
        : undefined,
    ),
  };
  mockedCookies.mockResolvedValue(
    jar as unknown as Awaited<ReturnType<typeof cookies>>,
  );
  return jar;
}

describe("login-nonce", () => {
  afterEach(() => vi.clearAllMocks());

  it("mints a random secret in an httpOnly __Host- cookie", async () => {
    const jar = stubCookieJar();

    const first = await ensureLoginNonce();
    stubCookieJar();
    const second = await ensureLoginNonce();

    expect(first).toMatch(/^[A-Za-z0-9_-]{43}$/);
    expect(second).not.toBe(first);
    expect(jar.set).toHaveBeenCalledWith(
      "__Host-login_nonce",
      first,
      expect.objectContaining({
        httpOnly: true,
        secure: true,
        sameSite: "lax",
        path: "/",
        maxAge: 1800,
      }),
    );
  });

  it("keeps the secret a pending link is bound to", async () => {
    const jar = stubCookieJar("existing-nonce");

    expect(await ensureLoginNonce()).toBe("existing-nonce");
    expect(jar.set).toHaveBeenCalledWith(
      "__Host-login_nonce",
      "existing-nonce",
      expect.anything(),
    );
  });

  it("reads and clears the cookie", async () => {
    const jar = stubCookieJar("n");

    expect(await readLoginNonce()).toBe("n");
    await clearLoginNonce();
    expect(jar.set).toHaveBeenCalledWith(
      "__Host-login_nonce",
      "",
      expect.objectContaining({ maxAge: 0, path: "/", secure: true }),
    );
  });
});
