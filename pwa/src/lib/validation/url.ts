/** Validate URL with http or https protocol */
export function isValidUrl(value: string): boolean {
  try {
    const url = new URL(value);
    return url.protocol === "https:" || url.protocol === "http:";
  } catch {
    return false;
  }
}

/** Validate URL with https protocol only */
export function isValidHttpsUrl(value: string): boolean {
  try {
    const url = new URL(value);
    return url.protocol === "https:";
  } catch {
    return false;
  }
}

/**
 * Supported source URL patterns.
 *
 * Mirrors the backend `RouteFetcherRegistry` strategies. The backend remains
 * the source of truth; these regexes provide fast frontend feedback before
 * the request is sent.
 */
export const SUPPORTED_SOURCE_PATTERNS: readonly RegExp[] = [
  /^https:\/\/www\.komoot\.com\/([a-z]{2}-[a-z]{2}\/)?tour\/\d+/,
  /^https:\/\/www\.komoot\.com\/([a-z]{2}-[a-z]{2}\/)?collection\/\d+/,
  /^https:\/\/www\.strava\.com\/routes\/\d+/,
  /^https:\/\/ridewithgps\.com\/routes\/\d+/,
] as const;

/** Check whether a URL matches one of the supported route source patterns. */
export function isSupportedSourceUrl(value: string): boolean {
  const trimmed = value.trim();
  if (!trimmed) return false;
  return SUPPORTED_SOURCE_PATTERNS.some((pattern) => pattern.test(trimmed));
}
