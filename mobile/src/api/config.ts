// Public API base URL, always from EXPO_PUBLIC_API_URL (see mobile/.env,
// documented in README). No built-in fallback: a hardcoded tunnel host goes
// stale and could later be claimed by a third party, with magic-link tokens and
// JWTs at stake.
export const API_BASE_URL = resolveApiBaseUrl();

function resolveApiBaseUrl(): string {
  const url = process.env.EXPO_PUBLIC_API_URL;
  if (!url) {
    throw new Error('EXPO_PUBLIC_API_URL must be set (see mobile/.env)');
  }
  // Fail closed on a plaintext base URL in a shipped build (#1174). Dev is
  // exempt (localhost / http tunnels).
  if (!__DEV__ && !url.startsWith('https://')) {
    throw new Error('EXPO_PUBLIC_API_URL must be an https URL for non-development builds');
  }
  return url;
}

// Public web origin serving the shared `/s/<code>` SSR page (#1048). The share
// link points at the web frontend, NOT the API, so a missing var must fail
// closed in non-dev builds rather than emit a dead link to the wrong origin.
// Mirrors API_BASE_URL's fail-closed contract.
export const WEB_BASE_URL =
  process.env.EXPO_PUBLIC_WEB_URL ?? getWebDevFallback();

function getWebDevFallback(): string {
  if (!__DEV__) {
    throw new Error('EXPO_PUBLIC_WEB_URL must be set for non-development builds');
  }
  return 'http://localhost:3000';
}

// The API is client-agnostic and negotiates on JSON-LD only (auth tokens travel in
// the body). Every mutating call sends and accepts application/ld+json.
export const LD_JSON = 'application/ld+json';

// Contact email shown in the account help screens (FAQ/legal/privacy — #1119).
// Mirrors the pwa's CONTACT_EMAIL (src/lib/constants.ts): both are fed by the
// root-level CONTACT_EMAIL. Display-only, so no fail-closed behaviour needed.
export const CONTACT_EMAIL =
  process.env.EXPO_PUBLIC_CONTACT_EMAIL ?? 'contact@bike-trip-planner.com';
