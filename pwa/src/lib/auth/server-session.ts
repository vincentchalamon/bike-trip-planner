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
 * - `{ authenticated, user }` when the backend answered;
 * - `null` when auth cannot be resolved server-side — the MOBILE static build
 *   (`output: export`, no server) OR a backend error/timeout. `null` means "let
 *   the client decide" (fail-OPEN): callers fall back to the client bootstrap
 *   (`AuthGuard` / `silentRefresh`), so a flaky backend never locks an
 *   authenticated user out or wrongly shows the landing.
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
    // No cookie → anonymous. The refresh_token is SameSite=Lax, so it IS sent on
    // a top-level navigation / deep-link; a missing one is authoritative. Skip
    // the network round-trip.
    return { authenticated: false, user: null };
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
