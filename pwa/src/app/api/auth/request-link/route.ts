import { NextResponse } from "next/server";
import { backendFetch } from "@/lib/auth/backend";
import { enforceSameOrigin } from "@/lib/auth/same-origin";

/** Reads the request/env at runtime, so it must never be prerendered. */
export const dynamic = "force-dynamic";

/**
 * BFF magic-link request. Forwards `{ email }` to the backend and mirrors its
 * neutral 202 (anti-enumeration). No cookie is involved at this stage.
 */
export async function POST(request: Request): Promise<NextResponse> {
  const denied = enforceSameOrigin(request);
  if (denied) return denied;

  const body = (await request.json().catch(() => ({}))) as { email?: unknown };
  const res = await backendFetch("/auth/request-link", {
    method: "POST",
    body: JSON.stringify({ email: body.email }),
  });

  return new NextResponse(null, { status: res.status });
}
