import { NextResponse } from "next/server";
import { backendFetch } from "@/lib/auth/backend";
import { enforceSameOrigin } from "@/lib/auth/same-origin";
import { setRefreshCookie } from "@/lib/auth/refresh-cookie";
import { clearLoginNonce, readLoginNonce } from "@/lib/auth/login-nonce";
import { syncLocaleFromAccount } from "@/i18n/account-locale";

/** Reads the request/env at runtime, so it must never be prerendered. */
export const dynamic = "force-dynamic";

/**
 * BFF magic-link verification. Forwards `{ token }` to the backend, then keeps
 * the `refresh_token` from the response in an httpOnly cookie and hands the
 * browser only the short-lived JWT. The refresh token never leaves the server.
 *
 * The login nonce cookie rides along: without it (a link opened in another
 * browser), the backend answers 409 with the account email, which is mirrored
 * so the page can ask before replaying with `confirm` (SEC-15).
 */
export async function POST(request: Request): Promise<NextResponse> {
  const denied = enforceSameOrigin(request);
  if (denied) return denied;

  const body = (await request.json().catch(() => ({}))) as {
    token?: unknown;
    confirm?: unknown;
  };
  const res = await backendFetch("/auth/verify", {
    method: "POST",
    body: JSON.stringify({
      token: body.token,
      nonce: await readLoginNonce(),
      confirm: body.confirm === true,
    }),
  });

  if (res.status === 409) {
    const { email } = (await res.json().catch(() => ({}))) as {
      email?: string;
    };
    return email
      ? NextResponse.json({ email }, { status: 409 })
      : new NextResponse(null, { status: 401 });
  }

  if (!res.ok) {
    return new NextResponse(null, { status: res.status });
  }

  const data = (await res.json()) as { token: string; refresh_token: string };
  await setRefreshCookie(data.refresh_token);
  await clearLoginNonce();
  await syncLocaleFromAccount(data.token);
  return NextResponse.json({ token: data.token });
}
