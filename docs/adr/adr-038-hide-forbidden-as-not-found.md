# ADR-038: Hide forbidden resources as 404 (object-level authorization)

- **Status:** Accepted
- **Date:** 2026-06-04
- **Depends on:** ADR-001 (Global Architecture), ADR-028 (Ollama integration — unrelated, referenced only for the audit context)
- **Origin:** Sprint 35.2 audit (finding `IDOR-DETAIL` fixed in #616; follow-up finding `ENUM-404`)

## Context and Problem Statement

All object-level authorization in the API gates trip resources by ownership through the
`TripVoter` (`TRIP_VIEW` / `TRIP_EDIT` / `TRIP_DELETE`), wired via API Platform `security:`
expressions on the trip, stage, chat-history, analyze and share operations.

Until now a denial returned **403 Forbidden**. Combined with the fact that a *non-existent*
trip returns **404 Not Found**, this leaks the **existence** of other users' trips: an
authenticated attacker probing UUIDs can tell "exists but not mine" (403) from "does not
exist" (404). This is a Broken Object Level / enumeration weakness (OWASP API #1/#3),
verified during the audit on the write endpoints:

```text
PATCH/DELETE  /trips/{unknown}  -> 404      GET /trips/{unknown}/detail  -> 404
PATCH/DELETE  /trips/{foreign}  -> 403      GET /trips/{foreign}/detail  -> 200 (pre-fix) / 403
```

## Decision

**Object-level authorization denials are surfaced as `404 Not Found`, not `403 Forbidden`,**
so a foreign trip is indistinguishable from a non-existent one and existence is never leaked.

RFC 7231 §6.5.4 explicitly sanctions this ("An origin server that wishes to 'hide' the current
existence of a forbidden target resource MAY instead respond with a status code of 404"); it is
also the convention used by GitHub for private resources a caller cannot access.

Implementation: a **`kernel.exception` listener** (`App\EventListener\HideForbiddenAsNotFoundListener`)
replaces the `AccessDeniedException` raised by the `security:` expressions with the same
`App\Exception\TripNotFoundException` (a `NotFoundHttpException` with a fixed message and no echoed id)
that the trip providers raise for a missing or expired trip. API Platform then renders one canonical
`problem+json` 404, so a foreign trip and a non-existent one are **byte-for-byte identical**.

The listener runs at **priority 2**, immediately before the firewall's own exception listener
(priority 1): it swaps the throwable so the firewall no longer treats it as an access denial. The swap
is gated on full authentication (`AuthenticationTrustResolverInterface::isFullFledged`, the same test the
firewall applies), so an anonymous caller still gets 401 from the entry point. The replacement carries no
`previous` link, because the firewall listener walks `getPrevious()` and would otherwise re-detect the
wrapped `AccessDeniedException` and re-handle it.

## Scope and boundaries

The mapping is **only** for object-level access denials:

- **Anonymous requests stay 401.** The listener swaps only for a *fully authenticated* caller
  (`isFullFledged`, the same test the firewall uses); an unauthenticated caller's denial is left
  untouched and the entry point turns it into 401 (`access_control` requires `IS_AUTHENTICATED_FULLY`
  on `^/`).
- **Owned-but-forbidden state denials stay 4xx of their own.** Editing a *locked* trip you own
  throws `HttpException(423)` (`TripLocker::assertNotLocked`), not `AccessDeniedException`, so it is
  untouched — the caller already knows the trip exists, hiding it would be wrong.
- **Today every `AccessDeniedException` in the app is an object-access denial** (`TRIP_*`); there are
  no direct `AccessDeniedHttpException` throws and no authenticated role-only 403s
  (`ROLE_USER` / `IS_AUTHENTICATED_FULLY` / `PUBLIC_ACCESS` never deny an authenticated user). The
  blanket swap is therefore correct. **If a future capability/role denial must stay 403** (a
  denial that does *not* hide an object's existence), it must bypass this listener by throwing an
  `AccessDeniedHttpException` (the HttpKernel one): the listener only intercepts the Security
  `AccessDeniedException`, so an `AccessDeniedHttpException` is rendered as 403 untouched. (API
  Platform throws a bare `AccessDeniedException` with no attributes, so the listener cannot scope by
  the denied attribute — hence the swap is unconditional for authenticated callers.) This boundary is
  the maintenance contract of this ADR.

## Consequences

### Positive

- No existence enumeration of other users' trips: foreign and non-existent both return 404.
- Uniform contract across reads and writes (detail, chat-history, analyze, stage, item GET/PATCH/DELETE, share).
- Spec-compliant (RFC 7231 §6.5.4) and matches a widely understood convention (GitHub).

### Negative

- Slightly less debuggable for an authenticated-but-unauthorized caller (a 404 where a 403 would be
  clearer). Negligible here: the PWA only ever requests the signed-in user's own trips, so legitimate
  flows never hit a foreign/forbidden trip.

### Neutral

- 401 (unauthenticated), 405 (method), 415 (media type), 422 (validation), 423 (locked) and 429
  (rate limit) keep their own semantics.

## Considered alternatives

- **Keep 403.** Rejected: leaks existence (the problem above).
- **Throw 404 in each provider/processor** instead of using `security:` expressions. Rejected: scatters
  the policy across many state classes, easy to forget on a new endpoint, and loses the single
  voter-based access model.
- **A firewall `access_denied_handler`.** The framework's intended hook for an authenticated denial,
  firing only for the authenticated-forbidden case (no auth gating needed). Rejected after testing: the
  handler must *return* a `Response` and cannot rethrow — throwing a `NotFoundHttpException` from it is
  caught by the firewall and re-wrapped as a 500 (*"Exception thrown when handling an exception"*). A
  hand-built return `Response` would also not match API Platform's `problem+json` 404, leaving a foreign
  trip distinguishable from a missing one by body on the object-load endpoints (where the missing case is
  raised by the provider, not the voter). The `kernel.exception` listener (priority 2, gated on full
  authentication) avoids both problems: it swaps the exception so API Platform renders one canonical 404.

## Sources

- RFC 7231 §6.5.4 (404 Not Found) and §6.5.3 (403 Forbidden).
- OWASP API Security Top 10 — API1:2023 Broken Object Level Authorization.
