import { headers } from "next/headers";

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

/**
 * Relays the inbound `X-Forwarded-For` chain, unchanged, to the backend.
 *
 * Without it every web user shares the pwa container's IP in the backend's
 * IP-keyed rate limiters. The chain is passed as received (not reduced to one
 * address): Symfony walks it from the right and skips `TRUSTED_PROXIES`, so the
 * hops Cloudflare, Traefik and Caddy appended still decide the client IP and a
 * value the client prepended is never picked.
 */
export function forwardedForHeader(
  incoming: Pick<Headers, "get">,
): Record<string, string> {
  const chain = incoming.get("x-forwarded-for")?.trim();
  return chain ? { "X-Forwarded-For": chain } : {};
}

export async function clientIpHeaders(): Promise<Record<string, string>> {
  return forwardedForHeader(await headers());
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
      ...(await clientIpHeaders()),
      ...init?.headers,
    },
    cache: "no-store",
    signal: AbortSignal.timeout(10_000),
  });
}
