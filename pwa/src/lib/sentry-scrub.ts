import type { Breadcrumb, Event } from "@sentry/nextjs";

/**
 * Takes what must never reach the error tracker out of a Sentry event: email
 * addresses, the credential parts of a URL (verify tokens, share codes, FCM
 * tokens, signed query values) and positions (`lat`/`lon` of a reverse
 * geocode). Mirrors the backend's App\Logger\LogRedactor.
 *
 * The browser SDK records the page URL with its fragment, which is where the
 * emailed verify links carry their token, and every fetch and navigation as a
 * breadcrumb; `sendDefaultPii: false` covers none of it.
 */
const REDACTED = "[redacted]";
const EMAIL =
  /[A-Za-z0-9._%+-]+(?:@|%40)[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/gi;
const SECRET_PATH =
  /(\/auth\/verify[/#]|\/account\/email-change\/verify[/#]|\/access-requests\/verify#|\/s\/|\/users\/me\/device-tokens\/(?!unregister\b))[^/?#\s"'<>.{]+/g;
const SECRET_QUERY =
  /([?&#](?:email|signature|token|share_token|access_token|refresh_token|id_token|code|state|apikey|api_key|key|password|lat|lon|lng|latitude|longitude)=)[^&#\s"'<>]*/gi;

export function scrubText(text: string): string {
  return text
    .replace(EMAIL, "[email]")
    .replace(SECRET_PATH, `$1${REDACTED}`)
    .replace(SECRET_QUERY, `$1${REDACTED}`);
}

/** A URL without its query string and fragment, its secret path segments redacted. */
export function scrubUrl(url: string): string {
  return scrubText(url.replace(/[?#][\s\S]*$/, ""));
}

function scrubValue(value: unknown): unknown {
  if (typeof value === "string") return scrubText(value);
  if (Array.isArray(value)) return value.map(scrubValue);
  if (value !== null && typeof value === "object") {
    return Object.fromEntries(
      Object.entries(value).map(([k, v]) => [k, scrubValue(v)]),
    );
  }
  return value;
}

export function scrubBreadcrumb(breadcrumb: Breadcrumb): Breadcrumb {
  return {
    ...breadcrumb,
    message:
      breadcrumb.message === undefined
        ? undefined
        : scrubText(breadcrumb.message),
    data:
      breadcrumb.data === undefined
        ? undefined
        : (scrubValue(breadcrumb.data) as Breadcrumb["data"]),
  };
}

export function scrubEvent<T extends Event>(event: T): T {
  if (event.request) {
    const { url, headers, method } = event.request;
    event.request = {
      method,
      url: url === undefined ? undefined : scrubUrl(url),
      headers:
        headers === undefined
          ? undefined
          : (scrubValue(headers) as Record<string, string>),
    };
  }
  if (event.message !== undefined) event.message = scrubText(event.message);
  for (const exception of event.exception?.values ?? []) {
    if (exception.value !== undefined) {
      exception.value = scrubText(exception.value);
    }
  }
  if (event.breadcrumbs) {
    event.breadcrumbs = event.breadcrumbs.map(scrubBreadcrumb);
  }
  if (event.transaction !== undefined) {
    event.transaction = scrubUrl(event.transaction);
  }
  for (const span of event.spans ?? []) {
    // Only an http span's description is a URL; a DB span's is SQL, where a
    // `?` is a placeholder, not the start of a query string.
    if (span.description !== undefined) {
      span.description = span.op?.startsWith("http")
        ? scrubUrl(span.description)
        : scrubText(span.description);
    }
    if (span.data) {
      delete span.data["http.query"];
      delete span.data["http.fragment"];
      span.data = scrubValue(span.data) as typeof span.data;
    }
  }
  const trace = event.contexts?.trace;
  if (trace?.data) {
    trace.data = scrubValue(trace.data) as typeof trace.data;
  }
  return event;
}
