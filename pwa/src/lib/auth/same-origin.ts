import { NextResponse } from "next/server";

/**
 * CSRF guard for the BFF auth route handlers.
 *
 * The refresh cookie is httpOnly and sent automatically by the browser, so a
 * cross-site page could otherwise drive `/api/auth/refresh|logout` on the
 * victim's behalf. We require the request to be same-origin.
 *
 * Primary signal: the `Sec-Fetch-Site` fetch-metadata header. It is set by the
 * browser and unreachable from JavaScript, so a cross-site attacker cannot
 * forge it — only `same-origin` passes (`same-site`, `cross-site`, `none` are
 * rejected). When the header is absent (older clients, non-browser callers) we
 * fall back to comparing the `Origin` header against the request's own origin.
 *
 * @returns a 403 {@link NextResponse} when the request is not same-origin, or
 * `null` when it passes.
 */
export function enforceSameOrigin(request: Request): NextResponse | null {
  const secFetchSite = request.headers.get("sec-fetch-site");
  if (secFetchSite !== null) {
    return secFetchSite === "same-origin" ? null : forbidden();
  }

  const origin = request.headers.get("origin");
  if (origin !== null && origin === new URL(request.url).origin) {
    return null;
  }
  return forbidden();
}

function forbidden(): NextResponse {
  return NextResponse.json({ error: "Forbidden" }, { status: 403 });
}
