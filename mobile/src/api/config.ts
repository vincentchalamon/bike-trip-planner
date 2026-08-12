// Public API base URL. Override at build time with EXPO_PUBLIC_API_URL (see .env,
// documented in README). Falls back to the shared ngrok tunnel used in dev.
export const API_BASE_URL =
  process.env.EXPO_PUBLIC_API_URL ?? 'https://epidermis-sandlot-headrest.ngrok-free.dev';

// Native clients identify themselves so the backend can serve a token-based
// (rather than cookie-based) auth contract. Sent on every request.
export const CLIENT_TYPE_HEADER = 'X-Client-Type';
export const CLIENT_TYPE_VALUE = 'native';
