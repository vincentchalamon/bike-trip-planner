import { afterEach, describe, expect, it, vi } from "vitest";
import { cookies } from "next/headers";
import {
  clearRefreshCookie,
  readRefreshCookie,
  setRefreshCookie,
} from "./refresh-cookie";

vi.mock("next/headers", () => ({ cookies: vi.fn() }));

const mockedCookies = vi.mocked(cookies);

function stubCookieJar(): {
  set: ReturnType<typeof vi.fn>;
  get: ReturnType<typeof vi.fn>;
} {
  const jar = { set: vi.fn(), get: vi.fn() };
  mockedCookies.mockResolvedValue(
    jar as unknown as Awaited<ReturnType<typeof cookies>>,
  );
  return jar;
}

describe("refresh-cookie", () => {
  afterEach(() => vi.clearAllMocks());

  it("sets an httpOnly, secure, sameSite=lax, path=/ cookie", async () => {
    const jar = stubCookieJar();

    await setRefreshCookie("tok");

    expect(jar.set).toHaveBeenCalledWith(
      "refresh_token",
      "tok",
      expect.objectContaining({
        httpOnly: true,
        secure: true,
        sameSite: "lax",
        path: "/",
      }),
    );
  });

  it("clears the cookie with an empty value and maxAge 0", async () => {
    const jar = stubCookieJar();

    await clearRefreshCookie();

    expect(jar.set).toHaveBeenCalledWith(
      "refresh_token",
      "",
      expect.objectContaining({
        httpOnly: true,
        secure: true,
        path: "/",
        maxAge: 0,
      }),
    );
  });

  it("reads the refresh_token cookie value", async () => {
    const jar = stubCookieJar();
    jar.get.mockReturnValue({ value: "tok" });

    expect(await readRefreshCookie()).toBe("tok");
    expect(jar.get).toHaveBeenCalledWith("refresh_token");
  });
});
