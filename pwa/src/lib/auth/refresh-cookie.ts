import { cookies } from "next/headers";

const REFRESH_COOKIE = "refresh_token";

/**
 * The refresh token has a 30-day TTL server-side, so the cookie mirrors that
 * window. NOTE: ideally the API would return the exact expiry alongside the
 * token so the cookie `maxAge` stays in lockstep with server-side rotation;
 * until it does, we hard-code the known 30-day lifetime here.
 */
const REFRESH_MAX_AGE_SECONDS = 60 * 60 * 24 * 30;

/**
 * Persist the httpOnly refresh cookie owned by the BFF. `sameSite: "lax"` lets
 * the cookie ride top-level navigations (e.g. the magic-link landing) while
 * still blocking cross-site POSTs; `secure` keeps it https-only.
 */
export async function setRefreshCookie(token: string): Promise<void> {
  (await cookies()).set(REFRESH_COOKIE, token, {
    httpOnly: true,
    secure: true,
    sameSite: "lax",
    path: "/",
    maxAge: REFRESH_MAX_AGE_SECONDS,
  });
}

/**
 * Drop the refresh cookie (logout, or a rejected/expired token). Cleared with
 * `sameSite: "strict"` so the deletion is not itself replayable cross-site.
 */
export async function clearRefreshCookie(): Promise<void> {
  (await cookies()).set(REFRESH_COOKIE, "", {
    httpOnly: true,
    secure: true,
    sameSite: "strict",
    path: "/",
    maxAge: 0,
  });
}

export async function readRefreshCookie(): Promise<string | undefined> {
  return (await cookies()).get(REFRESH_COOKIE)?.value;
}
