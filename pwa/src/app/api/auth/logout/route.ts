import { cookies } from "next/headers";
import { NextResponse } from "next/server";
import { backendFetch } from "@/lib/auth/backend";
import { enforceSameOrigin } from "@/lib/auth/same-origin";
import { clearRefreshCookie } from "@/lib/auth/refresh-cookie";

/**
 * The Mercure subscriber cookie the API sets (App\Mercure\MercureTokenIssuer). It is not
 * the session, but it still opens the trips' SSE topics for up to an hour, so it goes too.
 * Same name and path, or the browser keeps the original.
 */
const MERCURE_COOKIE = "__Secure-mercure_access_token";
const MERCURE_COOKIE_PATH = "/.well-known/mercure";

/** Reads the request/env at runtime, so it must never be prerendered. */
export const dynamic = "force-dynamic";

/**
 * BFF logout. Forwards the caller's `Authorization: Bearer` to the backend so it
 * can revoke the refresh tokens, then clears the local cookies no matter what —
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
  (await cookies()).set(MERCURE_COOKIE, "", {
    httpOnly: true,
    secure: true,
    sameSite: "strict",
    path: MERCURE_COOKIE_PATH,
    maxAge: 0,
  });
  return new NextResponse(null, { status: 204 });
}
