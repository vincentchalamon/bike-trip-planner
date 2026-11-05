import { NextResponse } from "next/server";
import { backendFetch } from "@/lib/auth/backend";
import { enforceSameOrigin } from "@/lib/auth/same-origin";
import { ensureLoginNonce } from "@/lib/auth/login-nonce";

/** Reads the request/env at runtime, so it must never be prerendered. */
export const dynamic = "force-dynamic";

/**
 * BFF magic-link request. Forwards `{ email }` to the backend with this
 * browser's login nonce, so only this browser can open the link without a
 * confirmation (SEC-15), and mirrors the neutral 202 (anti-enumeration).
 */
export async function POST(request: Request): Promise<NextResponse> {
  const denied = enforceSameOrigin(request);
  if (denied) return denied;

  const body = (await request.json().catch(() => ({}))) as { email?: unknown };
  const res = await backendFetch("/auth/request-link", {
    method: "POST",
    body: JSON.stringify({
      email: body.email,
      nonce: await ensureLoginNonce(),
    }),
  });

  return new NextResponse(null, { status: res.status });
}
