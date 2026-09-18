# ADR-063: Transport-Agnostic Authorization Expressions

- **Status:** Accepted
- **Date:** 2026-09-18
- **Depends on:** ADR-001 (Global Architecture), ADR-023 (Authentication Strategy)
- **Amends:** ADR-038 (Hide Forbidden As Not Found) — establishes that its masking is a
  property of the HTTP transport, not of the domain, and therefore does not hold on any
  other transport.
- **Evidence:** [MCP feasibility spike, 17/09/2026](../spikes/mcp-feasibility-2026-09-17.md)

## Context and Problem Statement

Every object-level authorization expression in this codebase reads the HTTP request:

```php
security: "is_granted('TRIP_VIEW', request.attributes.get('id'))"
security: "is_granted('TRIP_EDIT', request.attributes.get('tripId'))"
```

There are **19 such expressions across 7 files** — `Trip.php`, `Stage.php`,
`TripDetail.php`, `TripRoute.php`, `MercureToken.php`, `AccommodationScan.php` and
`Entity/TripShare.php`. That was harmless while HTTP was the only way in.

It is not harmless any more. The MCP spike proved that an API Platform operation can be
invoked **without an HTTP request** — at `tools/list` time, and from the CLI. In that
context `ApiPlatform\Mcp\Security\ExpressionAccessChecker` passes
`['request' => $requestStack?->getCurrentRequest()]`, i.e. **`request` is defined but
null**, and every one of the 19 expressions dies:

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

**Authorization expressions must not reference `request`.** Two forms replace it,
according to what the operation is keyed on.

### 1. Operations keyed on their own identifier (5 expressions) — `object`

```php
security: "is_granted('TRIP_VIEW', object.id)"
```

`object` is undefined at listing time, which raises a `SyntaxError` that
`ExpressionAccessChecker` **catches on purpose**: the element stays visible and the
expression is enforced on the call. This is a documented, intentional deferral, not a
workaround.

`object.id` rather than `object` because `TripVoter::supports()` accepts a `TripRequest`
entity or a string id, not the response DTO.

### 2. Operations keyed on a parent identifier (14 expressions) — per-URI-variable security

Three quarters of the expressions key on `tripId`, a **parent** identifier that `object`
does not carry, and `AccessCheckerProvider` exposes no `uriVariables` variable. Those move
onto the URI variable itself, where `SecurityParameterProvider` binds the value under its
own name:

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

### Authorization moves from before the read to after it

`request.attributes.get('id')` is evaluated before the object is loaded; `object.id` is
evaluated after. Another user's trip is therefore read from the database before being
refused. This changes no authorization outcome — the denial still happens — but it is an
ordering change worth stating, and a reason to keep the expression on `security`
(post-read) rather than trying to force everything into `pre_read`.

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
widens the voter's contract to solve a problem that `object.id` already solves, and does
nothing for the 14 parent-keyed expressions.

**Move all checks to `pre_read`.** Rejected: `AccessCheckerProvider` sets `object` to null
at that stage by construction, so it cannot express object-level rules at all.
