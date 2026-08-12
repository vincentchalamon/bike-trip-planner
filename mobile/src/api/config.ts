// Public API base URL. Override at build time with EXPO_PUBLIC_API_URL (see .env,
// documented in README). In dev only, falls back to the shared ngrok tunnel; a
// non-dev build without the var fails closed rather than shipping a stale ngrok
// host that a third party could later claim (magic-link tokens / JWTs at stake).
export const API_BASE_URL = process.env.EXPO_PUBLIC_API_URL ?? getDevFallback();

function getDevFallback(): string {
  if (!__DEV__) {
    throw new Error('EXPO_PUBLIC_API_URL must be set for non-development builds');
  }
  return 'https://epidermis-sandlot-headrest.ngrok-free.dev';
}

// The API is client-agnostic and negotiates on JSON-LD only (auth tokens travel in
// the body). Every mutating call sends and accepts application/ld+json.
export const LD_JSON = 'application/ld+json';
