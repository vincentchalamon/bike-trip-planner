import { NextResponse } from "next/server";
import { backendFetch } from "@/lib/auth/backend";
import { enforceSameOrigin } from "@/lib/auth/same-origin";
import {
  clearRefreshCookie,
  readRefreshCookie,
  setRefreshCookie,
} from "@/lib/auth/refresh-cookie";

/** Reads the cookie/env at runtime, so it must never be prerendered. */
export const dynamic = "force-dynamic";

/**
 * BFF token refresh. Reads the httpOnly `refresh_token` cookie, exchanges it at
 * the backend, and re-pins the rotated refresh token (the backend issues a
 * fresh one each call). Returns only the new JWT to the browser.
 *
 * A missing cookie, or a backend rejection, yields a clean 401 — and the stale
 * cookie is dropped so the browser stops replaying it.
 */
export async function POST(request: Request): Promise<NextResponse> {
  const denied = enforceSameOrigin(request);
  if (denied) return denied;

  const refreshToken = await readRefreshCookie();
  if (!refreshToken) {
    return new NextResponse(null, { status: 401 });
  }

  const res = await backendFetch("/auth/refresh", {
    method: "POST",
    body: JSON.stringify({ refresh_token: refreshToken }),
  });

  if (!res.ok) {
    await clearRefreshCookie();
    return new NextResponse(null, { status: 401 });
  }

  const data = (await res.json()) as { token: string; refresh_token: string };
  await setRefreshCookie(data.refresh_token);
  return NextResponse.json({ token: data.token });
}
