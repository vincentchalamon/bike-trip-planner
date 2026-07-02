# ADR-047: Server-Side Web Auth Resolution — Non-Rotating `/auth/session` + RSC Gate

- **Status:** Accepted
- **Date:** 2026-07-02
- **Depends on:** ADR-023 (Authentication Strategy — Passwordless Magic Link with JWT)
- **Enables:** #649 (#8)

## Context and Problem Statement

Auth is custom (no library, ADR-023): the Symfony backend is the source of truth (magic-link, httpOnly `refresh_token` cookie, 15-min JWT, rotating `/auth/refresh`); the PWA holds the JWT **in memory** (Zustand). The web server previously knew auth only through a **cookie-presence heuristic** — `app/page.tsx` did `cookies().has("refresh_token")`. That has two consequences:

1. **Landing flash / stale-cookie shell.** A present-but-expired/revoked cookie makes the server render the dashboard shell, which then "snaps back" once the client silent-refresh fails. The landing-vs-dashboard decision and the protected-route gate are otherwise **client-side** (`AuthGuard` renders `null` during its check, then redirects).
2. **"Wait for auth" on the client (recette #649 #8).** Because the server didn't resolve auth, the client fired API requests before the access token was minted → `401` → refresh → retry. PR #800 fixed the acute bug (the retry resent an empty body; a bootstrap `ensureResolved()` now gates requests), but the whole optimistic-then-recover dance exists only because the **server never resolved auth before render**.

We want the **web server to know the real auth state before rendering** — no flash, protected deep-links gated server-side — while keeping the mobile static build working and not weakening the in-memory-token security posture.

## Decision Drivers

- **No flash, know-before-render** on the web, from the *validated* session (not a cookie guess).
- **Idempotent + safe on every render/deep-link** — an SSR auth check must not mutate anything (no rotation, no `Set-Cookie`, no CSRF/replay surface on a GET).
- **Mobile static build (`output: export`, no server) must keep working** — a `middleware.ts`/`proxy.ts` file's mere presence breaks the export.
- **Preserve the in-memory-token posture** — never ship the JWT in the HTML.
- **Fail-safe** — a backend blip must never lock an authenticated user out or force the landing.

## Considered Options

### Option A — RSC gate + non-rotating `GET /auth/session` (chosen)

A new **read-only** endpoint `GET /auth/session` validates the `refresh_token` cookie (reusing `RefreshTokenRepository::findValidByToken()` + the deleted-account guard) and returns `{authenticated, userId?, email?}` **without rotating** — no `rotate()`, no `Set-Cookie`, no JWT issued. A server-only helper `resolveServerSession()` calls it (internal `API_BACKEND_URL`, forwarding only the `refresh_token` cookie); `app/page.tsx` uses it for a **validated** `initialAuthed`, and the `(app)/layout.tsx` Server Component **redirects anonymous users to `/login` before render**. Guarded by `NEXT_PUBLIC_IS_MOBILE_BUILD` so the static export no-ops.

### Option B — `proxy.ts` (Next 16 middleware)

Rejected. A single centralized gate, but: its **presence breaks `output: export`** (mobile), it runs on the **Edge runtime** (the internal `http://php` hostname isn't resolvable there), and it can only redirect/rewrite (not render). Its one unique advantage — writing cookies on the way through — is **moot**, because `/auth/session` is deliberately non-rotating (nothing to set).

### Option C — Status quo (client-only gate + cookie-presence heuristic)

Rejected. Leaves the flash, the stale-cookie shell, and the client "wait for auth" dance (only mitigated by PR #800).

### Option D — Full BFF (Next proxies every API call, holds the token server-side)

Rejected. Would remove the client token entirely but contradicts the decoupled "PWA talks to the API directly" model, adds a proxy hop to every call, and complicates Mercure/SSE. Far larger blast radius than the problem warrants.

## Decision

Adopt **Option A**. Concretely:

- **Backend** — `GET /auth/session` (`Auth` ApiResource) → `AuthSessionProvider` reusing the *validation half* of `AuthRefreshProcessor` **minus rotation**; `PUBLIC_ACCESS` (an anonymous caller gets `{authenticated:false}`, not a `401`). **Rotation vs introspection are now distinct**: `/auth/refresh` mutates (rotates the token, sets the cookie, mints a JWT); `/auth/session` only reads/validates. This is why it is safe to call on every render/deep-link.
- **Web gate (RSC)** — `resolveServerSession()` (`pwa/src/lib/auth/server-session.ts`) → `app/page.tsx` (validated `initialAuthed`) + `(app)/layout.tsx` (server redirect when the session is resolved-and-invalid). **Fail-open:** on the mobile build, when there is **no cookie to validate**, or on any backend error/timeout, it returns `null` → the client bootstrap stays authoritative. The gate is therefore scoped to what the server can actually *verify*: a **present-but-invalid/expired** cookie (the stale-shell case). A genuinely anonymous visitor (no cookie) is still gated client-side by `AuthGuard` — the server can't distinguish "never logged in" from "browser-layer-mocked test session", so treating a missing cookie as a hard redirect would break the mocked E2E suite for no security gain (protected content is already client-gated).
- **State-only, no token in HTML** — the server returns auth *state*, never a JWT. The client still mints its in-memory token via `silentRefresh` (the XSS posture of ADR-023 is preserved).
- **Client** — the JWT bootstrap, the `ensureResolved()` request-gate and the `401` retry (PR #800) **stay** (they mint the token, cover the 15-min mid-session expiry, and are the mobile path). `home-content.tsx` drops its duplicate bootstrap and defers to the shared, deduped `ensureResolved()`. `AuthGuard` remains the JWT bootstrap on every platform and the redirect **backstop** for mobile + web fail-open; on the web happy path its redirect is a no-op (the `(app)` layout preempts it).

## Consequences

### Positive

- No landing flash and no stale-cookie dashboard shell on the web; the landing-vs-dashboard decision is server-validated before first paint.
- A protected deep-link (e.g. `/trips/{id}`) opened with a stale/revoked cookie is redirected to `/login` server-side, before any protected chrome renders (no stale-cookie shell). A no-cookie anonymous visitor keeps the pre-existing client-side redirect.
- Removes the *cause* of the #8 "wait for auth" dance for web loads (the request now carries the token the server already knows is warranted); PR #800's retry becomes a pure mid-session-expiry safety net.

### Negative / Neutral

- One internal Next→Symfony round-trip per web page load (bounded 10 s, `no-store`); negligible and off the client critical path.
- The client auth code is **not removed** — mobile (no server), the in-memory JWT and the 15-min expiry all still require `silentRefresh`/`ensureResolved`/retry. Two bootstraps remain (deduped); scoping `AuthGuard` further is a deferred follow-up.
- Web and mobile now take **different** gate paths (server vs client), guarded by `NEXT_PUBLIC_IS_MOBILE_BUILD` — the same split already used for the SSR cookie read and route rewrites.

## References

- ADR-023 — Authentication Strategy (magic link + JWT + rotating refresh).
- PR #800 (recette #649 #8) — client-side interim fix (auth-readiness gating + retry body).
- `api/src/State/Auth/AuthSessionProvider.php`, `pwa/src/lib/auth/server-session.ts`, `pwa/src/app/(app)/layout.tsx`.
