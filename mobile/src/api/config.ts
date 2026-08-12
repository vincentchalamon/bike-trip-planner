// Public API base URL. Override at build time with EXPO_PUBLIC_API_URL (see .env,
// documented in README). Falls back to the shared ngrok tunnel used in dev.
export const API_BASE_URL =
  process.env.EXPO_PUBLIC_API_URL ?? 'https://epidermis-sandlot-headrest.ngrok-free.dev';

// The API is client-agnostic and negotiates on JSON-LD only (auth tokens travel in
// the body). Every mutating call sends and accepts application/ld+json.
export const LD_JSON = 'application/ld+json';
