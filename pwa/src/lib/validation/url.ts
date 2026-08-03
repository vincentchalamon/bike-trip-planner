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

/** A scheme prefix (`mailto:`, `javascript:`, `https://`…). Dots are excluded
 * on purpose so `www.hotel.fr:8080` is read as a host, not as a scheme. */
const SCHEME_PREFIX = /^[a-z][a-z0-9+-]*:/i;

/**
 * Normalize a hand-typed website value (OSM `website` tag, user input) into an
 * absolute http(s) URL, or `null` when it is not usable as a link.
 *
 * Last-resort frontend guard: the value reaches us straight from an OSM tag or
 * from the accommodation edit field, so schemeless values (`www.hotel.fr`) and
 * free text are both common. Returns the input untouched when it already is an
 * absolute http(s) URL.
 */
export function normalizeExternalUrl(
  value: string | null | undefined,
): string | null {
  const trimmed = value?.trim();
  if (!trimmed) return null;

  const hasScheme = SCHEME_PREFIX.test(trimmed);
  if (hasScheme && !/^https?:\/\//i.test(trimmed)) return null;

  const candidate = hasScheme ? trimmed : `https://${trimmed}`;
  let url: URL;
  try {
    url = new URL(candidate);
  } catch {
    return null;
  }
  // A hostname without a dot (`Hôtel`, `localhost`) is not a reachable website.
  if (!url.hostname.includes(".")) return null;

  return candidate;
}

/** Hostname to display for an external link, or `null` if unusable. */
export function externalUrlHostname(
  value: string | null | undefined,
): string | null {
  const normalized = normalizeExternalUrl(value);
  return normalized === null ? null : new URL(normalized).hostname;
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
