import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { resolveServerSession } from "@/lib/auth/server-session";
import { redirect } from "next/navigation";
import AppLayout from "./layout";

vi.mock("@/lib/auth/server-session", () => ({
  resolveServerSession: vi.fn(),
}));
vi.mock("next/navigation", () => ({ redirect: vi.fn() }));
vi.mock("@/components/site-chrome", () => ({
  SiteChrome: ({ children }: { children: ReactNode }) => children,
}));

describe("AppLayout — server-side auth gate (ADR-047)", () => {
  beforeEach(() => vi.clearAllMocks());

  it("redirects to /login when the session is resolved-and-invalid", async () => {
    vi.mocked(resolveServerSession).mockResolvedValue({
      authenticated: false,
      user: null,
    });

    await AppLayout({ children: null });

    expect(redirect).toHaveBeenCalledWith("/login");
  });

  it("does not redirect when the session is authenticated", async () => {
    vi.mocked(resolveServerSession).mockResolvedValue({
      authenticated: true,
      user: { id: "1", email: "a@b.com" },
    });

    await AppLayout({ children: null });

    expect(redirect).not.toHaveBeenCalled();
  });

  it("does not redirect when resolveServerSession fails open (null)", async () => {
    // Mobile build / no cookie / backend blip → let the client bootstrap decide.
    vi.mocked(resolveServerSession).mockResolvedValue(null);

    await AppLayout({ children: null });

    expect(redirect).not.toHaveBeenCalled();
  });
});
