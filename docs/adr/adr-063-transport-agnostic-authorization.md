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
invoked **without an HTTP request**. There are two failure modes, and the quieter one is
the dangerous one.

**Listing from the CLI — a loud failure.**
`ApiPlatform\Mcp\Security\ExpressionAccessChecker` passes
`['request' => $requestStack?->getCurrentRequest()]`, i.e. **`request` is defined but
null**, so the expression dies:

```text
Unable to get property "attributes" of non-object "request".
```

**Calling a tool over HTTP — a silent universal denial.** `ApiPlatform\Mcp\Server\Handler`
passes that same `getCurrentRequest()`, but over the MCP transport there *is* a current
request: `POST /mcp`. It simply is not the trip route. So
`request.attributes.get('tripId')` does not throw — it returns **null**,
`TripVoter::supports()` rejects null, the voter abstains, and **every caller is denied,
the owner included**. ADR-038 then masks that as a perfectly plausible 404.

An exception is survivable; a rule that silently denies everyone and looks like a normal
"not found" is not. This second mode is the real argument for the change.

The same coupling shows up outside authorization: `TripCreateProcessor` and
`TripUpdateProcessor` inject `RequestStack` solely to call
`getPreferredLanguage(['en','fr'])`, so a trip created by a non-browser client silently
gets the `en` fallback.

The underlying defect is one of **layering**: authorization and locale are domain
concerns that have been expressed in terms of a transport artefact.

## Decision

**Authorization expressions must not reference `request`.** A single form replaces it:
**name the URI variable**.

```php
- security: "is_granted('TRIP_EDIT', request.attributes.get('tripId'))"
+ security: "is_granted('TRIP_EDIT', tripId)"
```

`ApiPlatform\Symfony\Security\State\AccessCheckerProvider` already binds every URI
variable under its own name in the expression context:

```php
// URI variables are exposed to the expression (e.g. is_granted('VIEW', user_id));
// reserved keys below must win on collision.
$resourceAccessCheckerContext = [
    'object' => $body,
    'previous_object' => ...,
    'request' => $request,
] + $uriVariables;
```

So all 18 expressions are a one-token rewrite, with **no change to any `uriVariables`,
`Link` or provider**. The six `Trip.php` operations already using bare `object` stay as
they are: their provider returns a `TripRequest`, which `TripVoter::supports()` accepts.

### Why not `object.id`, and why not `Link(security:)`

Both were considered and both are worse.

`object.id` is unnecessary: every one of these operations is keyed on `{id}` or
`{tripId}`, so the URI variable is available — and available *earlier*, see below.

`Link(security:)` is actively wrong for the goal. It is enforced by
`SecurityParameterProvider`, whose `provide()` calls `$this->decorated->provide(...)` on
its **first** line and only then checks. The provider has already run. For
`MercureTokenProvider` that would mean **minting a Mercure JWT before refusing the
caller** — precisely what the URI-variable form was supposed to avoid.

### The check does not move: it stays before the provider

`AccessCheckerProvider` is wired **four times** into the chain, one of them at the
`pre_read` stage (decoration priority 10, the outermost). There it sets `$body = null`
and, when the expression references neither `object` nor `previous_object`
(`ResourceAccessChecker::usesObjectVariable()`, an AST walk), it **evaluates before
calling the decorated provider**.

`is_granted('TRIP_EDIT', tripId)` does not mention `object`, so the check happens exactly
where `request.attributes.get('tripId')` happened. **The HTTP behaviour is unchanged**,
`MercureTokenProvider` still mints nothing for a caller who will be refused, and its
docblock ("Ownership is enforced by the operation's `security` expression before this
runs") stays true.

`ReadListener` resolves `$uriVariables` *before* entering the chain, so they are in scope
from the outermost decorator. `read: false` does not change this: the listener always
calls the chain; it is `ReadProvider`, at the bottom, that consults `canRead()`.

### Both transports, one declaration

`config/state/security.php` (HTTP) and `config/mcp/security.php` (MCP) declare **the same
four decorators, at the same priorities, over the same
`api_platform.security.resource_access_checker`**.

On the MCP side, `Mcp\Server\Handler` fills `$uriVariables` from the JSON-RPC `arguments`
using the operation's declared URI variables, then calls that same chain. At `tools/list`
the variable is not yet bound, which raises a `SyntaxError` that `ExpressionAccessChecker`
**catches on purpose** — its comment names "uri variables" among the deferred cases, so
the element stays visible and the rule is enforced on the call. This form is the one the
framework anticipates.

> **⚠ Limit — `McpResource` has no URI variables.** That `Handler` loop is guarded by
> `if (!$isResource)`: an `McpResource` receives an empty `$uriVariables`, so this form
> does **not** apply to it. An MCP resource needing object-level authorization must be
> modelled as a tool, or express its rule another way. ADR-064's excluded-surface list
> proposes `export_trip` as an `McpResource`; that choice has to be revisited when the
> tools are built.

### Locale comes from the user, not from the request

`TripCreateProcessor`, `TripUpdateProcessor` and `GpxUploadController` read
`$user->getLocale()`. This removes `RequestStack` from both processors, and is more
correct regardless of transport: a stored preference beats a browser header the server
may never see.

**That preference had to become writable first.** `User::$locale` existed and defaulted to
`fr`, but only `CreateUserCommand --locale` ever wrote it — no route could change it.
Switching the trip locale onto it without opening a write would have made every trip
French for any account created with the default. So this ADR also introduces
**`PATCH /users/me`** (`AccountUpdate` input, `AccountMe` output), alongside the existing
`GET`/`DELETE`, with `User::SUPPORTED_LOCALES` replacing the four hard-coded `['fr','en']`
lists.

`/users/me`, not `/users/{id}`: the whole Account resource resolves the current user from
the security token and never from a URL identifier, which is what gives it no IDOR
surface. It is also the more transport-agnostic shape — an agent holding a token does not
know its user's UUID.

**The clients keep both directions in sync, and both are required.** Making the account
the source of truth for rendered content means the interface and the content can now
disagree, in either direction:

| Missing direction | What the user sees |
|---|---|
| The switcher does not push | Interface switches to English, alerts stay French, and only the CLI can fix it |
| Login does not read | Account is `en`, a fresh browser opens the interface in French next to English alerts |

So the language switchers `PATCH /users/me` (fire-and-forget: switching language is local
and immediate, and must not be held hostage to the network), and the session adopts the
account's locale when it starts — the web BFF writes the `locale` cookie from
`GET /users/me` while it still holds the fresh JWT, and the mobile session effect, which
already fetched that endpoint for the email, applies it to i18next.

Both clients were previously wired to carry the interface language *to* the server —
the PWA through `Accept-Language`, mobile through an explicit header its middleware sets
because React Native's `fetch` does not (#1169). This replaces that link rather than
removing it. On mobile it also supplies the language persistence the app never had: i18next
starts from the device locale, and the account's choice takes over on a restored session.

### `RequestStack` stays only where the transport genuinely is the subject

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

### Never convert a URI variable into an object

The value reaches the voter as the raw `string` from the route, which
`TripVoter::supports()` accepts. `UriVariablesConverter` leaves it alone here because the
identifier properties are declared `string` (or absent from the DTO), and the only
transformers registered are `integer`, `date_time` and `api_resource`.

"Tidying" a `Link` into `fromClass: TripRequest::class, identifiers: ['id']` would break
that: `TripRequest::$id` is a `Uuid`, `supports()` would return false, **the voter would
abstain and access would be denied silently** — and ADR-038 would dress the failure up as
a credible 404. A denial that comes from a misconfiguration is indistinguishable from a
denial that comes from the rule.

That is the general shape of the risk here, and it is why **every migrated expression
needs a functional test proving denial**, not a careful reading. The same reasoning
applies to `SecurityParameterProvider`, which does `continue` when it cannot resolve a
target resource (`getFromClass() ?? getToClass()`): a `Link` lacking a class carries **no**
authorization at all, without any error. That trap does not affect the form chosen here —
we do not use `Link(security:)` — but it is the reason not to reach for it later either.

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
