export interface ServerSession {
  authenticated: boolean;
  user: { id: string; email: string } | null;
}

/**
 * Server-side auth resolution for the WEB build (ADR-047).
 *
 * Validates the httpOnly `refresh_token` cookie against the backend's
 * non-rotating `GET /auth/session` so RSC gates can decide landing vs dashboard
 * (and redirect anonymous deep-links) BEFORE render — no landing flash, no
 * client "wait for auth" flicker.
 *
 * Returns:
 * - `{ authenticated, user }` when the backend answered (cookie present, valid
 *   or not — an invalid/expired cookie yields `{ authenticated: false }`, which
 *   is what lets the gate kill the stale-cookie shell);
 * - `null` when auth cannot be resolved server-side — the MOBILE static build
 *   (`output: export`, no server), NO cookie to validate, OR a backend
 *   error/timeout. `null` means "let the client decide" (fail-OPEN): callers
 *   fall back to the client bootstrap (`AuthGuard` / `silentRefresh`), so a
 *   flaky backend never locks an authenticated user out or wrongly shows the
 *   landing, and a genuinely anonymous visitor is still gated client-side.
 *
 * Only ever imported by Server Components; the mobile guard runs BEFORE any
 * `next/headers` access so the static export never bundles server-only APIs.
 */
export async function resolveServerSession(): Promise<ServerSession | null> {
  if (process.env.NEXT_PUBLIC_IS_MOBILE_BUILD === "1") {
    return null;
  }

  const { cookies } = await import("next/headers");
  const token = (await cookies()).get("refresh_token")?.value;
  if (!token) {
    // No cookie → we cannot VALIDATE anything, so fail-OPEN (`null`) and let the
    // client bootstrap decide, exactly like a backend error. This keeps the
    // server gate scoped to what it can actually verify — a PRESENT but
    // invalid/expired cookie (the stale-shell case) — while a genuinely
    // anonymous visitor is still gated client-side by `AuthGuard`. It also keeps
    // the mocked E2E suite working: those tests authenticate purely in the
    // browser (mocked `/auth/refresh`) and never carry a server-visible cookie.
    return null;
  }

  try {
    // INTERNAL backend URL (not the public https origin: avoids the self-signed
    // cert + hairpin routing), same as app/s/[code]/page.tsx.
    const backend = process.env.API_BACKEND_URL ?? "http://php";
    const res = await fetch(`${backend}/auth/session`, {
      // Forward ONLY the refresh_token — never the whole incoming Cookie header.
      headers: {
        Cookie: `refresh_token=${token}`,
        Accept: "application/ld+json",
      },
      cache: "no-store",
      signal: AbortSignal.timeout(10_000),
    });
    if (!res.ok) {
      return null; // fail-open
    }

    const data = (await res.json()) as {
      authenticated?: boolean;
      userId?: string | null;
      email?: string | null;
    };
    if (!data.authenticated || !data.userId || !data.email) {
      return { authenticated: false, user: null };
    }

    return {
      authenticated: true,
      user: { id: data.userId, email: data.email },
    };
  } catch {
    return null; // fail-open on timeout / network error
  }
}
