/**
 * Content-Security-Policy of the PWA's HTML documents (SEC-14).
 *
 * The only enforced script-src of the app. It is built per request by `src/proxy.ts`
 * because it carries a fresh nonce: Next.js reads the nonce back from the request's CSP
 * header and stamps it on its own inline bootstrap and chunk scripts. `'strict-dynamic'`
 * then lets those trusted scripts load the rest (lazy chunks, the Plausible tracker),
 * while a script the page did not create itself, inline or remote, does not run.
 *
 * Caddy still sets the non-fetch directives as a fallback for the responses this policy
 * does not reach (static files, route handlers); see `.docker/php/Caddyfile`.
 */

/** Map tiles and styles the browser fetches itself (MapLibre, the infographic). */
const MAP_ORIGINS = [
  "https://basemaps.cartocdn.com",
  "https://*.basemaps.cartocdn.com",
  "https://server.arcgisonline.com",
  "https://tile.openstreetmap.org",
];

export interface CspOptions {
  nonce: string;
  isDev: boolean;
  reportUri?: string;
  /** Absolute URLs whose origin the page talks to (Sentry DSN, Plausible, a remote API). */
  connectUrls?: (string | undefined)[];
}

function originOf(url: string | undefined): string | null {
  if (!url) return null;
  try {
    const { origin } = new URL(url);
    return origin === "null" ? null : origin;
  } catch {
    return null;
  }
}

export function buildContentSecurityPolicy({
  nonce,
  isDev,
  reportUri,
  connectUrls = [],
}: CspOptions): string {
  const connect = new Set(["'self'", ...MAP_ORIGINS]);
  for (const url of connectUrls) {
    const origin = originOf(url);
    if (origin) connect.add(origin);
  }

  const directives = [
    "default-src 'self'",
    // 'self' is only read by browsers too old for 'strict-dynamic'. 'unsafe-eval' is
    // what the React dev build needs to rebuild server stacks; production never evals.
    `script-src 'self' 'nonce-${nonce}' 'strict-dynamic'${isDev ? " 'unsafe-eval'" : ""}`,
    // 'unsafe-inline' stays for styles, and no nonce may be added here (it would make the
    // browser ignore 'unsafe-inline'): React renders `style` attributes, and Radix,
    // MapLibre, sonner and driver.js inject inline styles at runtime. A nonce cannot
    // cover attributes, and styles cannot run code.
    "style-src 'self' 'unsafe-inline'",
    // POI and accommodation thumbnails come from whatever host the source data names.
    "img-src 'self' data: blob: https:",
    "font-src 'self' data:",
    `connect-src ${[...connect].join(" ")}`,
    // MapLibre runs its workers from /vendor/maplibre, through a blob: bootstrap.
    "worker-src 'self' blob:",
    "manifest-src 'self'",
    "object-src 'none'",
    "base-uri 'self'",
    "form-action 'self'",
    "frame-ancestors 'none'",
  ];
  if (reportUri) directives.push(`report-uri ${reportUri}`);

  return directives.join("; ");
}

/** 128 random bits, base64: unguessable and fresh for every document. */
export function generateNonce(): string {
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  return btoa(String.fromCharCode(...bytes));
}
