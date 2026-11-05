import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import "@testing-library/jest-dom/vitest";
import VerifyPage from "./verify-page";

vi.mock("next-intl", () => ({
  useTranslations: () => {
    const fn = (key: string) => key;
    fn.rich = (key: string, values?: Record<string, unknown>) =>
      values && "email" in values ? `${key}:${String(values.email)}` : key;
    return fn;
  },
}));

const replace = vi.fn();
vi.mock("next/navigation", () => ({ useRouter: () => ({ replace }) }));

const setAuth = vi.fn();
vi.mock("@/store/auth-store", () => ({
  useAuthStore: (selector: (state: { setAuth: typeof setAuth }) => unknown) =>
    selector({ setAuth }),
  parseJwtPayload: () => ({ sub: "user-1", email: "owner@example.com" }),
}));

function json(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "content-type": "application/json" },
  });
}

function sentBodies(fetchMock: ReturnType<typeof vi.fn>): unknown[] {
  return fetchMock.mock.calls.map(([, init]) =>
    JSON.parse((init as RequestInit).body as string),
  );
}

describe("VerifyPage", () => {
  beforeEach(() => {
    window.history.replaceState(null, "", "/auth/verify#magic-token");
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
  });

  it("signs in at once when the link was requested from this browser", async () => {
    const fetchMock = vi.fn().mockResolvedValue(json(200, { token: "a.b.c" }));
    vi.stubGlobal("fetch", fetchMock);

    await act(async () => {
      render(<VerifyPage />);
    });

    expect(setAuth).toHaveBeenCalledWith("a.b.c", {
      id: "user-1",
      email: "owner@example.com",
    });
    expect(replace).toHaveBeenCalledWith("/");
    expect(sentBodies(fetchMock)).toEqual([
      { token: "magic-token", confirm: false },
    ]);
  });

  it("names the account and waits for the user before signing in elsewhere", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(json(409, { email: "owner@example.com" }))
      .mockResolvedValueOnce(json(200, { token: "a.b.c" }));
    vi.stubGlobal("fetch", fetchMock);

    await act(async () => {
      render(<VerifyPage />);
    });

    expect(screen.getByTestId("magic-link-confirm-email")).toHaveTextContent(
      "owner@example.com",
    );
    expect(setAuth).not.toHaveBeenCalled();

    await act(async () => {
      fireEvent.click(screen.getByTestId("magic-link-confirm-continue"));
    });

    expect(setAuth).toHaveBeenCalled();
    // The fragment is gone from the URL by now: the replay reuses the token read on mount.
    expect(sentBodies(fetchMock)).toEqual([
      { token: "magic-token", confirm: false },
      { token: "magic-token", confirm: true },
    ]);
  });

  it("leaves without signing in when the user cancels", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue(json(409, { email: "owner@example.com" }));
    vi.stubGlobal("fetch", fetchMock);

    await act(async () => {
      render(<VerifyPage />);
    });
    fireEvent.click(screen.getByTestId("magic-link-confirm-cancel"));

    expect(replace).toHaveBeenCalledWith("/");
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(setAuth).not.toHaveBeenCalled();
  });

  it("shows the expired state for a rejected link", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(json(401, {})));

    await act(async () => {
      render(<VerifyPage />);
    });

    expect(screen.getByTestId("magic-link-expired")).toBeInTheDocument();
    expect(screen.queryByTestId("magic-link-confirm")).not.toBeInTheDocument();
  });
});
