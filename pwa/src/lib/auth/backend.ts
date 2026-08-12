/**
 * Server-to-server calls to the INTERNAL backend from the Next.js BFF route
 * handlers (`src/app/api/auth/*`).
 *
 * Uses the internal container URL (`API_BACKEND_URL`, default `http://php`) —
 * not the public https origin — to avoid the self-signed cert + hairpin
 * routing, exactly like {@link resolveServerSession} (server-session.ts). Talks
 * JSON-LD and never caches (auth is always fresh).
 *
 * Only ever imported by server-side route handlers.
 */
export function backendUrl(): string {
  return process.env.API_BACKEND_URL ?? "http://php";
}

export async function backendFetch(
  path: string,
  init?: RequestInit,
): Promise<Response> {
  return fetch(`${backendUrl()}${path}`, {
    ...init,
    headers: {
      "Content-Type": "application/ld+json",
      Accept: "application/ld+json",
      ...init?.headers,
    },
    cache: "no-store",
    signal: AbortSignal.timeout(10_000),
  });
}
