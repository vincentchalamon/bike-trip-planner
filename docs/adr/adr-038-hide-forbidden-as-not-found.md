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

Implementation: a firewall **`access_denied_handler`** (`App\Security\HideForbiddenAsNotFoundHandler`)
that converts the `AccessDeniedException` raised by the `security:` expressions into a
`NotFoundHttpException`, rendered by API Platform as a normal `problem+json` 404. The handler is
wired on the `api` firewall in `config/packages/security.php`.

## Scope and boundaries

The mapping is **only** for object-level access denials:

- **Anonymous requests stay 401.** A firewall invokes its access-denied handler only *after*
  authentication; unauthenticated callers are turned into 401 by the entry point before the handler
  runs (`access_control` requires `IS_AUTHENTICATED_FULLY` on `^/`).
- **Owned-but-forbidden state denials stay 4xx of their own.** Editing a *locked* trip you own
  throws `HttpException(423)` (`TripLocker::assertNotLocked`), not `AccessDeniedException`, so it is
  untouched — the caller already knows the trip exists, hiding it would be wrong.
- **Today every `AccessDeniedException` in the app is an object-access denial** (`TRIP_*`); there are
  no direct `AccessDeniedHttpException` throws and no authenticated role-only 403s
  (`ROLE_USER` / `IS_AUTHENTICATED_FULLY` / `PUBLIC_ACCESS` never deny an authenticated user). The
  blanket handler is therefore correct. **If a future capability/role denial must stay 403** (a
  denial that does *not* hide an object's existence), it must bypass this handler — e.g. throw an
  `AccessDeniedHttpException` (which the handler does not intercept) or branch on the denied
  attribute. This boundary is the maintenance contract of this ADR.

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
- **A `kernel.exception` listener** converting `AccessDeniedException` to `NotFoundHttpException`.
  Workable but has to be ordered carefully against the firewall's own exception listener (which turns
  the anonymous case into 401). The `access_denied_handler` is the framework's intended hook and fires
  only for the authenticated-forbidden case, so it needs no priority juggling.

## Sources

- RFC 7231 §6.5.4 (404 Not Found) and §6.5.3 (403 Forbidden).
- OWASP API Security Top 10 — API1:2023 Broken Object Level Authorization.
