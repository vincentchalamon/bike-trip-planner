# ADR-083 - The nonce belongs to whoever renders the page

**Status:** accepted
**Date:** 2026-11-05

## Context

The PWA documents had no enforced `script-src`. Caddy sent the full policy as
`Content-Security-Policy-Report-Only`, with `'unsafe-inline'` for scripts and a
`report-uri` wired to nothing, and enforced only the non-fetch directives
(`object-src`, `frame-ancestors`, `base-uri`, `form-action`). The access token lives in
memory, so a script injected into any page could read it, and nothing would have
stopped it.

`'unsafe-inline'` was there because Next.js bootstraps the App Router with inline
scripts. The way to drop it is a per-request nonce, and the nonce has to be known by
the code that writes the `<script>` tags. That is Next.js at render time, not the edge.

## Decision

1. **The documents' CSP is set by Next.js**, in `pwa/src/proxy.ts`: a fresh 128-bit nonce
   per request, `script-src 'self' 'nonce-...' 'strict-dynamic'`, no `'unsafe-inline'` and
   no `'unsafe-eval'` for scripts outside `next dev`. Next.js reads the nonce from the
   request's CSP header and stamps it on its own scripts; the root layout hands it to
   next-themes, the only inline script the app renders itself. `'strict-dynamic'` lets
   those scripts load the rest (lazy chunks, the Plausible tracker) without an origin
   allowlist. The policy is built by `pwa/src/lib/security/csp.ts`.
2. **Caddy keeps the non-fetch directives as a fallback** (`header @pwa ?Content-Security-Policy`):
   set only when the PWA did not answer with a policy, i.e. on static files, the BFF route
   handlers and the MapLibre worker scripts. Two policies would be intersected by the
   browser, so there is exactly one `script-src` for a document.
3. **`style-src` keeps `'unsafe-inline'`, without a nonce.** React renders `style`
   attributes, and Radix, MapLibre, sonner and driver.js inject styles at runtime; a nonce
   cannot cover an attribute, and its presence would make the browser ignore
   `'unsafe-inline'`. Styles do not execute code.
4. **`img-src` allows any `https:` origin**: POI and accommodation thumbnails come from
   whatever host the source data names. `connect-src` stays an allowlist: `'self'`, the
   map tile and style origins, and the origins of the configured Sentry DSN, Plausible
   tracker and (when set) remote API / Mercure hub.
5. **Violations are reported to `CSP_REPORT_URI`** when set (pwa service, rendered by
   Ansible); unset means no `report-uri` at all rather than an empty one.

## Consequences

- Every document is dynamically rendered. It already was (the root layout reads the
  locale cookie); the layout now also reads `x-nonce`, so a page can no longer become
  static by accident and ship un-nonced scripts.
- An inline script, an inline event handler or a `javascript:` URL injected into a page
  no longer runs. A new third-party script must be created by an already trusted script
  (`next/script` does this) or it is blocked.
- The CSP is no longer read in the Caddyfile alone: the edge carries the fallback, the
  application carries the policy. Changing an allowed origin is a change to `csp.ts`.
- The mocked Playwright suite fails on console errors (`runtime-monitor.ts`), so a CSP
  violation on a covered page shows up in CI as a red test.
