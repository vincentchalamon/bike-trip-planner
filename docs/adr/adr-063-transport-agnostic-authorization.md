# ADR-063: Transport-Agnostic Authorization Expressions

- **Status:** Accepted
- **Date:** 2026-09-18
- **Depends on:** ADR-001 (Global Architecture), ADR-023 (Authentication Strategy)
- **Amends:** ADR-038 (Hide Forbidden As Not Found) — establishes that its masking is a
  property of the HTTP transport, not of the domain, and therefore does not hold on any
  other transport.
- **Evidence:** [MCP feasibility spike, 17/09/2026](../spikes/mcp-feasibility-2026-09-17.md)

## Context and Problem Statement

Most object-level authorization expressions in this codebase read the HTTP request:

```php
security: "is_granted('TRIP_VIEW', request.attributes.get('id'))"
security: "is_granted('TRIP_EDIT', request.attributes.get('tripId'))"
```

Counted on `api/src`, there are **24 object-level expressions**, of which **6 already use
the `object` form** — all in `Trip.php` (lines 84, 100, 117, 133, 142, 161). **18 remain
coupled to the request**, across 7 files: `Trip.php`, `Stage.php`, `TripDetail.php`,
`TripRoute.php`, `MercureToken.php`, `AccommodationScan.php` and `Entity/TripShare.php`.

This ADR therefore **finishes a migration that is already a quarter done**, rather than
starting one. That the `object` form is already in production here, working, is the best
evidence that it is the right target.

It is not harmless any more. The MCP spike proved that an API Platform operation can be
invoked **without an HTTP request** — at `tools/list` time, and from the CLI. In that
context `ApiPlatform\Mcp\Security\ExpressionAccessChecker` passes
`['request' => $requestStack?->getCurrentRequest()]`, i.e. **`request` is defined but
null**, and every one of the 18 remaining expressions dies:

```text
Unable to get property "attributes" of non-object "request".
```

The same coupling shows up outside authorization: `TripCreateProcessor` and
`TripUpdateProcessor` inject `RequestStack` solely to call
`getPreferredLanguage(['en','fr'])`, so a trip created by a non-browser client silently
gets the `en` fallback.

The underlying defect is one of **layering**: authorization and locale are domain
concerns that have been expressed in terms of a transport artefact.

## Decision

**Authorization expressions must not reference `request`.** Three forms replace it, and
the one to use is decided by **what the operation's provider returns** — not by what the
operation is keyed on, which is the intuitive but wrong criterion.

| The provider returns… | Form | Count |
|---|---|---|
| a `TripRequest` entity, which `TripVoter::supports()` accepts | `object` | **already done** (6) |
| a DTO exposing the trip id | `object.id` | **3** |
| anything else, or the operation is keyed on a parent `tripId` | per-URI-variable security | **15** |

### 1. The provider returns a `TripRequest` — bare `object`

Already the case for the six `Trip.php` operations whose provider is
`TripRequestProvider` or `TripDoctrineProvider`. Nothing to do.

### 2. The provider returns a DTO carrying the id — `object.id` (3 expressions)

```php
security: "is_granted('TRIP_VIEW', object.id)"
```

`object` is undefined at listing time, which raises a `SyntaxError` that
`ExpressionAccessChecker` **catches on purpose**: the element stays visible and the
expression is enforced on the call. This is a documented, intentional deferral, not a
workaround.

`object.id` rather than bare `object` because `TripVoter::supports()` accepts a
`TripRequest` entity or a string id, not a response DTO.

Applies to `Trip.php:155` (`TripGpxProvider` → `Trip`), `TripRoute.php`
(→ `TripRoute`) and `TripDetail.php` (→ `TripDetail`) — the three DTOs that expose an
`id`.

### 3. Parent identifier, or a DTO with no id — per-URI-variable security (15 expressions)

Fourteen expressions key on `tripId`, a **parent** identifier that `object` does not
carry, and `AccessCheckerProvider` exposes no `uriVariables` variable.

**A fifteenth joins them for a different reason:** `MercureToken.php` is keyed on its own
`id`, but `MercureTokenProvider` returns a `MercureToken` DTO whose only property is
`$token`. There is no `id` to read, so `object.id` is impossible there.

That case also carries the sharpest version of the ordering caveat below: `object.*`
is evaluated **after** the provider has run, so using it here would mean **minting a
Mercure JWT before refusing the caller**. The URI-variable form avoids that entirely.

All fifteen move onto the URI variable itself, where `SecurityParameterProvider` binds
the value under its own name:

```php
uriVariables: [
    'tripId' => new Link(fromClass: Stage::class, security: "is_granted('TRIP_EDIT', tripId)"),
    'index' => new Link(toProperty: 'dayNumber', fromClass: Stage::class),
],
```

`SecurityParameterProvider` is present in both the HTTP and the MCP provider chains, so a
single declaration covers both transports.

### 3. Locale comes from the user, not from the request

`TripCreateProcessor` and `TripUpdateProcessor` read `$user->getLocale()`. The `locale`
column already exists on `User` and is already used by `AuthRequestLinkProcessor` and
`RequestEmailChangeProcessor`. This removes `RequestStack` from both processors, and is
more correct regardless of transport: a stored preference beats a browser header.

### 4. `RequestStack` stays only where the transport genuinely is the subject

`/auth/*` and the early-access flow (`AuthSessionProvider`, `AuthRequestLinkProcessor`,
`AccessRequestCreateProcessor`, `RequestEmailChangeProcessor`) may keep it: they are
browser-bound by design and are excluded from any non-HTTP transport.

## Consequences

### The masking of ADR-038 does not survive a change of transport

ADR-038 hides object-level denials as 404 so that trip UUIDs cannot be enumerated. It is
implemented by `HideForbiddenAsNotFoundListener`, an **HTTP-kernel exception listener**.
The spike measured what a non-HTTP transport returns:

```text
someone else's trip : Access Denied.
unknown trip        : Trip "01936f6e-…-09ff" not found.
```

**Two distinguishable answers — the enumeration ADR-038 closes on HTTP is open
elsewhere.** This ADR records the cause rather than the symptom: the masking is a
rendering rule that lives in the transport layer, so it evaporates the moment the domain
is reached by another road.

Any transport added to this application must therefore either reproduce the masking or
explicitly accept that it does not apply. That decision belongs to the ADR introducing the
transport, and is taken for MCP in ADR-064.

### Authorization moves from before the provider to after it

`request.attributes.get('id')` is evaluated before the provider runs; `object.id` is
evaluated after. The denial still happens, so no authorization outcome changes — but
**the provider's side effects happen first**, for a caller who will be refused.

For the three `object.id` cases that is a database read, which is benign. It is the
reason `MercureToken` must **not** take that form: its provider mints a JWT, and work of
that nature should not be done for an unauthorized caller even when the result is
discarded. Reading the ordering as "one extra SELECT" would have missed it.

This is also why the expression stays on `security` (post-provider) rather than being
forced into `pre_read`: `AccessCheckerProvider` sets `object` to null at that stage by
construction, so object-level rules cannot be expressed there at all.

### A misconfigured `Link` skips its check silently

`SecurityParameterProvider` does `continue` when it cannot resolve a target resource
(`getFromClass() ?? getToClass()`). A URI variable whose `Link` lacks a class therefore
carries **no** authorization at all, without any error. Every per-URI-variable expression
needs a functional test proving denial, not just review.

### Positive

- Authorization becomes a property of the domain, expressible once for every transport.
- Two processors lose their `RequestStack` dependency.
- The HTTP behaviour is unchanged: the same expressions work, only their inputs differ.

## Alternatives considered

**Keep `request.` and give each transport its own expressions.** Rejected: it doubles the
authorization surface — the part of the codebase where duplication is least acceptable —
and guarantees the two copies drift.

**Extend `TripVoter` to accept the response DTOs, and use bare `object`.** Rejected: it
widens the voter's contract to solve a problem that `object.id` already solves, does
nothing for the 15 expressions that need the URI-variable form, and would not help
`MercureToken` at all — its DTO carries no trip identity for the voter to read.

**Move all checks to `pre_read`.** Rejected: `AccessCheckerProvider` sets `object` to null
at that stage by construction, so it cannot express object-level rules at all.
