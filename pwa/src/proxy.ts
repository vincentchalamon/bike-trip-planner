import { NextResponse, type NextRequest } from "next/server";
import { buildContentSecurityPolicy, generateNonce } from "@/lib/security/csp";

/**
 * Sets the per-request nonce CSP on HTML documents (SEC-14). Next.js reads the nonce
 * from the REQUEST's Content-Security-Policy header while rendering, and the browser
 * enforces the RESPONSE's; both carry the same policy. The root layout reads `x-nonce`
 * for the one inline script it renders itself (next-themes).
 */
export function proxy(request: NextRequest): NextResponse {
  const nonce = generateNonce();
  const policy = buildContentSecurityPolicy({
    nonce,
    isDev: process.env.NODE_ENV === "development",
    reportUri: process.env.CSP_REPORT_URI || undefined,
    connectUrls: [
      process.env.NEXT_PUBLIC_SENTRY_DSN,
      process.env.NEXT_PUBLIC_PLAUSIBLE_SRC,
      process.env.NEXT_PUBLIC_API_URL,
      process.env.NEXT_PUBLIC_MERCURE_URL,
    ],
  });

  const requestHeaders = new Headers(request.headers);
  requestHeaders.set("x-nonce", nonce);
  requestHeaders.set("Content-Security-Policy", policy);

  const response = NextResponse.next({ request: { headers: requestHeaders } });
  response.headers.set("Content-Security-Policy", policy);

  return response;
}

export const config = {
  matcher: [
    {
      // Documents only: not the BFF route handlers, Next's static output, the
      // MapLibre worker files, public assets or anything with a file extension.
      source:
        "/((?!api/|_next/static|_next/image|vendor/|images/|\\.well-known/|.*\\.[a-zA-Z0-9]+$).*)",
      missing: [
        { type: "header", key: "next-router-prefetch" },
        { type: "header", key: "purpose", value: "prefetch" },
      ],
    },
  ],
};
