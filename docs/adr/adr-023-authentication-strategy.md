# ADR-023: Authentication Strategy — Passwordless Magic Link with JWT

- **Status:** Accepted
- **Date:** 2026-03-25
- **Depends on:** ADR-022 (Persistent Storage Strategy)
- **Enables:** #76 (Auth implementation), #80 (Shared read-only trips)

## Context and Problem Statement

Bike Trip Planner needs an authentication layer to associate trips with users, control access, and enable features like trip listing, duplication, and sharing. The application follows a decoupled architecture (Symfony API backend + Next.js frontend) and must support both browser and future mobile (Capacitor) clients.

The authentication mechanism must be:

- **Stateless** — consistent with the existing API Platform architecture (no server-side sessions)
- **Self-hostable at zero cost** — no dependency on paid identity providers
- **Mobile-compatible** — functional in Capacitor WebView where cookie behavior is unreliable
- **Low friction** — minimize user effort for an invite-only audience of bikepacking enthusiasts

### Invite-Only Model

The application does not offer self-registration. Users are created exclusively via a CLI command by the administrator. This eliminates the need for a registration flow, email verification during sign-up, and password reset mechanisms — dramatically simplifying the authentication surface.

---

## Decision Drivers

- **Security** — minimize attack surface (no password database to breach, no credentials to phish)
- **Simplicity** — reduce implementation and maintenance burden for a single-developer project
- **Stateless architecture** — preserve the existing API Platform State Provider/Processor pattern
- **Multi-platform** — must work in browser and Capacitor WebView without behavioral differences
- **Cost** — zero infrastructure cost; SMTP via Resend free tier (100 emails/day)

---

## Considered Options

| Approach | Stateless? | Mobile? | Self-hosted $0? | Complexity |
|---|---|---|---|---|
| **JWT + magic link** | Yes | Yes | Yes | Medium |
| JWT + login/password | Yes | Yes | Yes | Medium |
| Session cookies | No | Fragile | Yes | Low |
| API token (DB lookup) | No | Yes | Yes | Medium |
| OAuth2 server | Yes | Yes | Yes | High |

### Option A: JWT + Magic Link (chosen)

The user enters their email address. If the email corresponds to an existing user, a time-limited magic link is sent. Clicking the link authenticates the user and issues a JWT access token + refresh token pair. No password is ever stored, transmitted, or remembered.

**Pros:**

- Zero password storage eliminates an entire class of vulnerabilities (credential stuffing, brute-force, password reuse)
- Minimal UI surface: one input field, one button
- Natural fit for invite-only: the admin creates users with just an email
- Stateless JWT integrates cleanly with API Platform

**Cons:**

- Depends on email delivery reliability
- Slight latency (user must switch to email client)

### Option B: JWT + Login/Password

Traditional email + password authentication with JWT tokens.

**Pros:**

- Well-understood pattern with extensive library support
- No dependency on email delivery for every login

**Cons:**

- Requires password hashing, storage, and reset flow
- Increases attack surface (credential stuffing, brute-force, password reuse attacks)
- More UI to build and maintain (registration, login, forgot password, reset password)
- Overkill for an invite-only audience

### Option C: Session Cookies

Server-side sessions stored in Redis or database.

**Pros:**

- Simplest implementation, native Symfony support
- Easy revocation (delete session)

**Cons:**

- Breaks stateless architecture; requires sticky sessions or shared session store
- Fragile in Capacitor WebView (cookie partitioning, ITP restrictions)
- Does not scale to multiple API instances without shared state

### Option D: API Token (Database Lookup)

Long-lived opaque tokens stored in the database, sent as Bearer header.

**Pros:**

- Simple to implement
- Easy revocation (delete token row)

**Cons:**

- Database lookup on every request — not stateless
- Long-lived tokens increase window of compromise
- No standard refresh mechanism

### Option E: OAuth2 Server

Full OAuth2 authorization server (e.g., league/oauth2-server-bundle).

**Pros:**

- Industry standard, supports multiple grant types
- Future-proof for third-party integrations

**Cons:**

- Significant implementation complexity for a single-developer project
- Requires managing clients, scopes, consent screens
- Massive overkill for an invite-only application with no third-party consumers

---

## Decision

**JWT + passwordless magic link** using LexikJWTAuthenticationBundle.

### Authentication Flow

```text
┌─────────┐         ┌──────────┐         ┌─────────┐         ┌──────────┐
│ Browser  │         │ Next.js  │         │ Symfony  │         │  Resend  │
│          │         │ Frontend │         │ Backend  │         │  (SMTP)  │
└────┬─────┘         └────┬─────┘         └────┬─────┘         └────┬─────┘
     │  Enter email       │                    │                     │
     │───────────────────►│  POST /auth/request-link  │                     │
     │                    │───────────────────►│                     │
     │                    │                    │  Generate token      │
     │                    │                    │  (256-bit entropy)   │
     │                    │                    │                     │
     │                    │                    │  Send magic link     │
     │                    │  202 Accepted      │────────────────────►│
     │                    │◄───────────────────│                     │
     │  "Check your email"│                    │                     │
     │◄───────────────────│                    │                     │
     │                    │                    │                     │
     │  Click magic link  │                    │                     │
     │───────────────────────────────────────►│                     │
     │                    │                    │  Validate token      │
     │                    │                    │  (TTL + single-use)  │
     │                    │                    │                     │
     │                    │  JWT access token   │                     │
     │                    │  + refresh cookie   │                     │
     │◄───────────────────────────────────────│                     │
     │                    │                    │                     │
```

### Token Strategy

| Token | Storage | Lifetime | Purpose |
|---|---|---|---|
| **Magic link token** | PostgreSQL (`magic_link_tokens` table) | 30 min, single use | One-time authentication |
| **Access token (JWT)** | In-memory (JavaScript variable) | 15 min | API request authorization |
| **Refresh token** | HttpOnly SameSite=Strict cookie | 30 days | Silent access token renewal |

### Uniform Response Policy

All requests to the magic link generation endpoint return the same `202 Accepted` response with a neutral message ("If this email is registered, a login link has been sent"), regardless of:

- Whether the email exists in the database
- Whether a valid magic link already exists for this email
- Whether the throttling limit has been reached

This prevents user enumeration and leaks no information about the internal state of the system.

### Single Active Link Policy

If a valid (non-expired, non-consumed) magic link already exists for a given email, no new link is generated or sent. This prevents the multiplication of valid tokens and reduces SMTP usage.

---

## Security Measures

### Token Security

- **Magic link entropy:** 256 bits (cryptographically secure random bytes), making brute-force infeasible
- **Magic link TTL:** 30 minutes, single use — consumed immediately upon verification
- **Access token:** stored in JavaScript memory only (never `localStorage` or `sessionStorage`) — immune to XSS exfiltration via storage APIs
- **Refresh token:** HttpOnly + SameSite=Strict cookie — inaccessible to JavaScript, resistant to CSRF

### Throttling

Rate limiting on the magic link generation endpoint to prevent mailbox spam and SMTP quota exhaustion:

- **Per email:** max 3 requests per 15-minute window
- **Per IP:** max 3 requests per 15-minute window
- Implemented via Symfony RateLimiter component with Redis backend

### Capacitor (Mobile) Adaptation

In Capacitor WebView, HttpOnly cookies may not be reliably transmitted. When the backend detects a Capacitor Origin header, the refresh token is returned in the response body instead of a cookie. The mobile client stores it in the device's secure storage (Capacitor Preferences with encryption).

---

## Technical Implementation

### Backend (Symfony / API Platform)

- **LexikJWTAuthenticationBundle** for JWT generation and validation
- **Custom authenticator** for magic link token verification
- **Doctrine entity** `MagicLinkToken` with columns: `token` (hashed), `email`, `expires_at`, `consumed_at`
- **Symfony Mailer** with Resend SMTP transport for magic link delivery
- **Symfony RateLimiter** for throttling (Redis-backed sliding window)
- **Custom API Platform State Processor** for the `/auth/request-link` endpoint

### Frontend (Next.js)

- Access token held in a Zustand store (in-memory, not persisted)
- `fetch` wrapper automatically attaches `Authorization: Bearer` header
- Silent refresh via `credentials: 'include'` on the refresh endpoint
- Redirect to login page when both tokens are expired

### Prerequisites

- PostgreSQL + Doctrine (ADR-022, #56) — required for `MagicLinkToken` entity and `User` entity persistence
- SMTP provider configured (Resend free tier)

---

## Consequences

### Positive

- **Zero password surface** — no password hashing, no reset flow, no credential database to breach
- **Minimal UI** — single email input for authentication; no registration form needed
- **Stateless API** — JWT validation requires no database lookup on regular requests
- **Invite-only simplicity** — user creation via CLI eliminates self-registration complexity
- **Email as identity** — the magic link implicitly proves email ownership

### Negative

- **Email dependency** — authentication is blocked if email delivery fails or is delayed
- **Context switching** — users must leave the app to check their email
- **SMTP cost at scale** — Resend free tier limits to 100 emails/day (sufficient for invite-only, but would need upgrade for public access)

### Neutral

- **Refresh token rotation** — a future enhancement could invalidate the previous refresh token upon each renewal (detect token theft)
- **Device management** — users cannot currently see or revoke active sessions across devices

---

## References

- [LexikJWTAuthenticationBundle](https://github.com/lexik/LexikJWTAuthenticationBundle)
- [Symfony RateLimiter](https://symfony.com/doc/current/rate_limiter.html)
- [Resend SMTP](https://resend.com/docs/send-with-smtp)
- [OWASP Authentication Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html)
