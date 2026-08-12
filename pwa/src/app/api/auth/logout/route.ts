import { NextResponse } from "next/server";
import { backendFetch } from "@/lib/auth/backend";
import { enforceSameOrigin } from "@/lib/auth/same-origin";
import { clearRefreshCookie } from "@/lib/auth/refresh-cookie";

/** Reads the request/env at runtime, so it must never be prerendered. */
export const dynamic = "force-dynamic";

/**
 * BFF logout. Forwards the caller's `Authorization: Bearer` to the backend so it
 * can revoke the refresh tokens, then clears the local cookie no matter what —
 * the device session is over regardless of the backend outcome.
 */
export async function POST(request: Request): Promise<NextResponse> {
  const denied = enforceSameOrigin(request);
  if (denied) return denied;

  const authorization = request.headers.get("authorization");
  try {
    await backendFetch("/auth/logout", {
      method: "POST",
      headers: authorization ? { Authorization: authorization } : {},
    });
  } catch {
    // Ignore — the cookie is dropped below regardless.
  }

  await clearRefreshCookie();
  return new NextResponse(null, { status: 204 });
}
