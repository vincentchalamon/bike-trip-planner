import { randomBytes } from "node:crypto";
import { cookies } from "next/headers";

/**
 * The secret that binds a magic link to the browser that asked for it (SEC-15).
 * `__Host-` pins it to this origin (Secure, Path=/, no Domain), and it never
 * reaches the page's JS: the BFF sends it with the request-link and the verify.
 */
const LOGIN_NONCE_COOKIE = "__Host-login_nonce";

/** A magic link lives 30 minutes (MagicLinkRepository::TTL_MINUTES). */
const LOGIN_NONCE_MAX_AGE_SECONDS = 30 * 60;

/**
 * The nonce to send with a request-link. An existing one is kept: the backend
 * issues no second link while one is pending, so a fresh secret would orphan it.
 */
export async function ensureLoginNonce(): Promise<string> {
  const jar = await cookies();
  const nonce =
    jar.get(LOGIN_NONCE_COOKIE)?.value || randomBytes(32).toString("base64url");
  jar.set(LOGIN_NONCE_COOKIE, nonce, {
    httpOnly: true,
    secure: true,
    sameSite: "lax",
    path: "/",
    maxAge: LOGIN_NONCE_MAX_AGE_SECONDS,
  });
  return nonce;
}

export async function readLoginNonce(): Promise<string | undefined> {
  return (await cookies()).get(LOGIN_NONCE_COOKIE)?.value;
}

export async function clearLoginNonce(): Promise<void> {
  (await cookies()).set(LOGIN_NONCE_COOKIE, "", {
    httpOnly: true,
    secure: true,
    sameSite: "lax",
    path: "/",
    maxAge: 0,
  });
}
