import { NextResponse } from "next/server";
import { backendFetch } from "@/lib/auth/backend";
import { enforceSameOrigin } from "@/lib/auth/same-origin";
import { setRefreshCookie } from "@/lib/auth/refresh-cookie";

/** Reads the request/env at runtime, so it must never be prerendered. */
export const dynamic = "force-dynamic";

/**
 * BFF magic-link verification. Forwards `{ token }` to the backend, then keeps
 * the `refresh_token` from the response in an httpOnly cookie and hands the
 * browser only the short-lived JWT. The refresh token never leaves the server.
 */
export async function POST(request: Request): Promise<NextResponse> {
  const denied = enforceSameOrigin(request);
  if (denied) return denied;

  const body = (await request.json().catch(() => ({}))) as { token?: unknown };
  const res = await backendFetch("/auth/verify", {
    method: "POST",
    body: JSON.stringify({ token: body.token }),
  });

  if (!res.ok) {
    return new NextResponse(null, { status: res.status });
  }

  const data = (await res.json()) as { token: string; refresh_token: string };
  await setRefreshCookie(data.refresh_token);
  return NextResponse.json({ token: data.token });
}
