# ADR-029: Early Access System — HMAC Verification, IP Throttling, and CLI Management

- **Status:** Accepted
- **Date:** 2026-04-16
- **Depends on:** ADR-022 (Persistent Storage Strategy), ADR-023 (Authentication Strategy)
- **Enables:** #316 (Early access system)

## Context and Problem Statement

Bike Trip Planner is transitioning from a fully closed, CLI-provisioned invite-only model (ADR-023) to a controlled public beta. Prospective users need a way to express interest and request early access, while the administrator retains full control over who is granted access.

The mechanism must balance three competing concerns:

1. **Privacy** — The system must not leak whether a given email address has already submitted a request (email enumeration is a significant privacy risk and enables targeted phishing).
2. **Anti-spam** — A public-facing form is a natural target for automated abuse. Flooding the request queue with fake addresses would degrade operator experience and potentially exhaust outbound email quotas.
3. **Operator simplicity** — The administrator is a single developer. The review workflow must be usable from a terminal without requiring a dedicated admin UI.

---

## Decision Drivers

- **No email enumeration** — Responses must be uniform regardless of whether the email already exists in the system.
- **Anti-spam / rate limiting** — Automated submissions must be discouraged at the infrastructure level, not just the application level.
- **CLI-first operator workflow** — No admin UI required; a Symfony console command is sufficient.
- **No token persistence for verification** — Avoid storing short-lived verification tokens in the database; keep the `access_request` table append-only and simple.
- **CSRF protection** — The submission endpoint must be protected against cross-site request forgery.
- **Stateless verification** — HMAC signatures allow verification without a database round-trip.

---

## Considered Options

### Option A: Direct Invitation (Chosen for ADR-023, not applicable here)

The administrator manually creates accounts via CLI. No request flow exists.

**Why not applicable:** This model requires the administrator to proactively source users. For a beta program, the goal is to let users express interest organically and then review the backlog.

### Option B: Email Verification with Database Token

User submits email → system stores a short-lived token in the database → user clicks a confirmation link → request is marked as confirmed.

**Pros:**

- Proves email address ownership before adding to the queue.
- Standard, well-understood pattern.

**Cons:**

- Requires a `verification_tokens` table or additional column — more schema complexity.
- Token cleanup (expiry, garbage collection) adds operational burden.
- Does not solve email enumeration: a "token already sent" vs. "token sent" distinction leaks information.
- Adds friction for the user (two steps before appearing in the queue).

### Option C: Open Registration with Post-Hoc Moderation

Anyone can create an account; the administrator reviews and approves/bans.

**Pros:**

- Maximum simplicity for the user.
- No waiting period before access.

**Cons:**

- Requires a full user provisioning and de-provisioning workflow.
- Spam accounts consume real resources before moderation.
- Loss of control — rejected accounts may have already explored the application.
- Incompatible with the invite-only model (ADR-023) where users are created exclusively by the administrator.

### Option D: Waiting List with HMAC Verification (Chosen)

User submits email → system stores the request with `pending` status → HMAC-signed approval token is generated on demand by the CLI → administrator sends the approval link out-of-band → user clicks the link to activate their account (or the CLI promotes them directly).

**Pros:**

- Single database table (`access_request`), append-only, no token column needed at submission time.
- HMAC verification is stateless: the token encodes `(email, timestamp, status)` and is verified by recomputing the signature — no token lookup required.
- Uniform response policy eliminates email enumeration.
- CLI command covers the entire operator workflow: list, approve, reject.
- IP throttling (max 3 requests/hour) is implemented at the Symfony RateLimiter level — independent of application logic.

**Cons:**

- HMAC tokens have no built-in revocation (if the secret key leaks, all tokens are compromised). Mitigated by using a dedicated `APP_ACCESS_REQUEST_HMAC_SECRET` (kept out of the database) that can be rotated independently of `APP_SECRET`.
- The administrator must use the CLI or copy a link manually — no one-click dashboard.

---

## Decision

**Option D: Waiting list with HMAC verification, IP throttling, and Symfony CLI.**

### Data Model

A single Doctrine entity `AccessRequest` backed by an `access_request` table:

| Column | Type | Notes |
|---|---|---|
| `id` | UUID v7 | Primary key |
| `email` | VARCHAR(255) | Unique index (`#[UniqueConstraint]`); enforced at DB level via `INSERT … ON CONFLICT (email) DO NOTHING` to make duplicate submissions idempotent under concurrent load — see silent duplicate policy |
| `ip_address` | VARCHAR(45) | IPv4 or IPv6, stored for throttling audit |
| `status` | ENUM(`pending`, `approved`, `rejected`) | Default: `pending` |
| `created_at` | TIMESTAMPTZ | Immutable, set on insert |
| `updated_at` | TIMESTAMPTZ | Updated on status change |

There is **no token column**. Verification tokens are generated on-demand by the CLI using HMAC and are never persisted.

### Request Flow

```text
┌──────────┐         ┌──────────┐         ┌──────────┐
│  Browser  │         │ Symfony  │         │ Operator │
│           │         │ Backend  │         │  (CLI)   │
└─────┬─────┘         └─────┬────┘         └────┬─────┘
      │  POST /access-requests                   │
      │  {email, _csrf_token}  │                 │
      │──────────────────────►│                 │
      │                       │  Check IP rate  │
      │                       │  limit (Redis)  │
      │                       │                 │
      │                       │  If email exists│
      │                       │  → silently     │
      │                       │    ignore       │
      │                       │                 │
      │                       │  Insert row     │
      │                       │  (status=pending│
      │  202 Accepted          │                 │
      │◄──────────────────────│                 │
      │                       │                 │
      │                       │   app:access-request:list
      │                       │◄────────────────│
      │                       │  List pending   │
      │                       │────────────────►│
      │                       │                 │
      │                       │   app:access-request:approve <email>
      │                       │◄────────────────│
      │                       │  Generate HMAC  │
      │                       │  token, update  │
      │                       │  status=approved│
      │                       │────────────────►│
      │                       │  (prints link   │
      │                       │   or sends mail)│
```

### HMAC Token Design

Tokens are generated by the CLI `app:access-request:approve` command and are **never stored in the database**.

**Token payload:**

```text
HMAC-SHA256(
  key   = APP_ACCESS_REQUEST_HMAC_SECRET,
  input = "early_access:{email}:{approved_at_unix_timestamp}"
)
```

A dedicated environment variable (`APP_ACCESS_REQUEST_HMAC_SECRET`) is used rather than reusing `APP_SECRET`. Symfony's `APP_SECRET` already signs CSRF tokens, remember-me cookies, and password-reset payloads; binding approval links to it would force a full cross-subsystem invalidation every time any one of those contexts was rotated. A dedicated secret keeps the blast radius of a rotation (or a leak) scoped to the early-access activation feature. The variable must be documented alongside `APP_SECRET` in `.env.dist` and in the deployment runbook.

The approval link sent to the user encodes:

```text
https://app.example.com/activate?email={email}&ts={approved_at_unix_timestamp}&sig={hmac_hex}
```

**Verification (activation endpoint):**

1. Extract `email`, `ts`, `sig` from the query string.
2. Reject if `ts` is older than 72 hours (configurable TTL).
3. Recompute `HMAC-SHA256(APP_ACCESS_REQUEST_HMAC_SECRET, "early_access:{email}:{ts}")` and compare to `sig` in constant time (`hash_equals`).
4. If valid: look up `AccessRequest` by `email` and `status=approved`. Check whether a `User` for this email already exists — if so, skip creation and proceed directly to issuing credentials (idempotent activation). Otherwise, create the `User` entity (same as the CLI provisioning path in ADR-023).
5. On success (user created or already existed): return a JWT access token and refresh token so the browser can log in immediately.
6. On failure (invalid/expired HMAC, or `status` ≠ `approved`): return `202 Accepted` with the same neutral body as the submission endpoint to prevent enumeration of approved accounts. The uniform-response anti-enumeration policy applies to the failure path only; the success path must emit credentials so the frontend can redirect into the authenticated app.

### Silent Duplicate Policy

If a request is submitted for an email that already has a row in `access_request` (any status), the system:

- Does **not** insert a duplicate row.
- Does **not** return an error.
- Returns the same `202 Accepted` response as a fresh submission.

This prevents:

- User enumeration (attacker cannot distinguish "already submitted" from "first submission").
- Queue pollution from accidental double-submissions.

### IP Throttling

Rate limiting is applied **before** any database access, at the Symfony RateLimiter layer:

- **Limit:** 3 requests per hour per IP address.
- **Backend:** Redis sliding window (`cache.rate_limiter` pool).
- **Response on limit exceeded:** same `202 Accepted` — the response is indistinguishable from a successful submission to prevent probing.
- **Key:** `early_access__{ip_address}` (sanitized for Redis key safety).

### CSRF Protection

The submission endpoint requires a valid Symfony CSRF token:

- Token ID: `early_access_request`.
- The frontend fetches the token via `GET /csrf-token/early_access_request` before rendering the form.
- The API Platform State Processor validates the token via `CsrfTokenManagerInterface` and returns `422 Unprocessable Entity` on failure (the only non-202 response the endpoint emits, since CSRF failure is not information an attacker can exploit for enumeration).

### CLI Commands

All operator interactions go through a single Symfony console command group:

| Command | Description |
|---|---|
| `app:access-request:list` | List all requests, optionally filtered by `--status=pending\|approved\|rejected`. Outputs a formatted table with ID, email, IP, status, and date. |
| `app:access-request:approve <email>` | Set status to `approved`, generate HMAC approval link, and optionally send it via email (requires `--send-email` flag). |
| `app:access-request:reject <email>` | Set status to `rejected`. No email is sent. |

### Uniform Response Policy

All outcomes of `POST /access-requests` return `202 Accepted` with a neutral body:

```json
{ "message": "Your request has been received. You will be notified if access is granted." }
```

This applies to:

- Successful insertion.
- Silent duplicate (email already exists).
- IP rate limit exceeded.
- (CSRF failure is the only exception: `422 Unprocessable Entity`.)

---

## Security Measures

| Threat | Mitigation |
|---|---|
| Email enumeration | Uniform 202 response for all outcomes |
| CSRF | Symfony CSRF token validated on every submission |
| Automated spam | IP throttling: max 3 req/hour via Redis RateLimiter |
| HMAC forgery | Dedicated `APP_ACCESS_REQUEST_HMAC_SECRET` (distinct from `APP_SECRET`), never stored in DB; constant-time comparison via `hash_equals` |
| Token replay | 72-hour TTL enforced at activation time via `ts` field in URL |
| Brute-force on HMAC | HMAC-SHA256 with full `APP_ACCESS_REQUEST_HMAC_SECRET` entropy; no feasible brute-force |
| Duplicate requests | Silent ignore; no information leak, no DB pollution |

---

## Consequences

### Positive

- **Single table, no token columns** — The `access_request` table is append-only with only status transitions; no ephemeral token cleanup required.
- **Stateless HMAC verification** — Approval tokens verified without a database lookup; scales naturally.
- **No enumeration surface** — Uniform responses across all submission outcomes.
- **CLI-native workflow** — Operator workflow requires no browser or admin UI; composable with scripts and cron jobs.
- **Throttling is infrastructure-level** — Redis RateLimiter operates before any business logic; spam is blocked early.

### Negative

- **No built-in token revocation** — Once an HMAC approval link is generated, it is valid for 72 hours regardless of a subsequent `reject` CLI call. Mitigation: if a rejection occurs after approval, the `access_request` status is set to `rejected`, and the activation endpoint checks the database status before creating the user.
- **CLI-only operator interface** — No web dashboard. Acceptable for a single-developer project; a future admin UI can be layered on top of the same `AccessRequest` entity.
- **HMAC secret rotation invalidates all outstanding links** — Rotating `APP_ACCESS_REQUEST_HMAC_SECRET` forces re-approval of any pending activation links. Acceptable given the low volume of a beta program, and the scope of invalidation stays contained to early-access activation (no impact on CSRF tokens, remember-me cookies, or password resets signed with `APP_SECRET`).
- **Extra environment variable to manage** — Operators must provision and rotate `APP_ACCESS_REQUEST_HMAC_SECRET` separately from `APP_SECRET`. The variable is documented alongside `APP_SECRET` in `.env.dist` and in the deployment runbook so the rotation procedure is discoverable.

### Neutral

- **No change to authentication flow** — Once a user is created via the activation endpoint (or directly via CLI), authentication proceeds exactly as described in ADR-023 (magic link + JWT).
- **IP address storage** — The `ip_address` column is stored for throttling audit purposes, not for profiling. Privacy implications are minimal; the data is visible only to the administrator via the CLI.

---

## References

- [Symfony RateLimiter](https://symfony.com/doc/current/rate_limiter.html)
- [Symfony CSRF Protection](https://symfony.com/doc/current/security/csrf.html)
- [OWASP Testing for Account Enumeration](https://owasp.org/www-project-web-security-testing-guide/stable/4-Web_Application_Security_Testing/03-Identity_Management_Testing/04-Testing_for_Account_Enumeration_and_Guessable_User_Account)
- [HMAC (RFC 2104)](https://www.rfc-editor.org/rfc/rfc2104)
- [ADR-023: Authentication Strategy](adr-023-authentication-strategy.md)
- [ADR-022: Persistent Storage Strategy](adr-022-persistent-storage-strategy.md)
