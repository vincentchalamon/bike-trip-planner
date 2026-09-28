# Spike — Feasibility of an MCP server in the API (17/09/2026)

> **Written verdict of a time-boxed investigation.** It ran on the throwaway branch `spike/mcp-feasibility`, never merged and deleted on 28/09/2026; this document is what remains of it.
> It gave rise to [ADR-063](../adr/adr-063-transport-agnostic-authorization.md) and
> [ADR-064](../adr/adr-064-mcp-server-as-third-api-client.md), which carry the decisions;
> this document keeps the measurements and the paths taken to get there.
> The findings below were all verified by execution, not inferred from documentation.

## Overall verdict

**Feasible, and markedly cheaper than the plan assumed on the transport side -
markedly more expensive on one point the plan had not seen (the security
expressions).** No blocking obstacle was encountered.

What was set up in one session: the packages installed, the server configured, a
`get_trip` tool declared on an existing resource by reusing its provider, and the
`/mcp` route served behind the existing JWT firewall - **without a single line of
authentication code**.

---

## Installed versions, without conflict

| Package | Version |
|---|---|
| `api-platform/mcp` | **v4.3.19** |
| `mcp/sdk` | **v0.8.1** |
| `symfony/mcp-bundle` | **v0.13.0** |

Installed on PHP 8.5 / Symfony 8.1 / API Platform 4.3.18 **without any constraint
conflict**. `api-platform/mcp` depends on `mcp/sdk ^0.8` and on `symfony/object-mapper`
(already present), **not** on `symfony/mcp-bundle`: the bundle is nonetheless still required,
since `ApiPlatformExtension` makes activation conditional on `class_exists(McpBundle::class)`.

Additional dependency discovered: **`psr/simple-cache`** is required as soon as the
`cache` session store is chosen - the one the FrankenPHP worker mode imposes. Without
it, the container breaks on `Attempted to load interface "CacheInterface" from namespace
"Psr\SimpleCache"`.

---

## Answers to the spike's five questions

### (a) Does `api-platform/mcp` provide the OAuth resource server?

**No - but `mcp/sdk` does, and completely.** The SDK ships
`ProtectedResourceMetadataMiddleware` (RFC 9728), `AuthorizationMiddleware`,
`JwtTokenValidator`, `JwksProvider`, `OidcDiscovery`, `AuthorizationTokenValidatorInterface`,
`ClientRegistrationMiddleware` and `OAuthProxyMiddleware`.

The SDK even carries an ADR on the subject - `adr/0001-oauth-authorization-server-out-of-scope.md` -
whose conclusion matches the plan's analysis word for word:

> The MCP server is an OAuth 2.1 Resource Server that MAY delegate to an upstream
> authorization server. It will NOT issue tokens or act as an Identity Provider.

And its "what to do instead" section explicitly recommends:

> **Run `league/oauth2-server` in your own application**, behind the SDK's existing proxy
> and validator seams.

**Consequence for Phase 3A**: the "thin layer" the plan intended to write
(RFC 9728 metadata, `WWW-Authenticate`, token validation) **is already delivered**. What remains
is the AS itself. And `OAuthProxyMiddleware` - a seam the plan did not know about - can
delegate `/authorize` and `/token` to an upstream AS, which reduces the wiring further.

**Immediate caveat**: **`symfony/mcp-bundle` does NOT expose this OAuth middleware.** Its
`Http/MiddlewareFactory` only handles DNS-rebinding protection. Plugging in the SDK's resource
server through the bundle therefore requires decorating the middleware stack yourself -
or, more idiomatic here, relying on the Symfony firewall, which the project already has.

### (b) Does the FrankenPHP worker mode hold up?

**Handled by design, not disproved.** `McpRegistryPass` explicitly documents the case
and decorates each server's registry to load the API Platform elements *on
first read*, "to heal a persistent runtime (e.g. FrankenPHP worker mode) where the SDK
builds the registry once and may capture an empty state".

Operational corollary confirmed: the session store must be `cache`, not `file` or
`memory` - the bundle config itself says so for PHP-FPM ("the publisher and the
stream are different workers"). Hence the `psr/simple-cache` dependency above.

**Not verified under real load**: the spike did not run N concurrent agents
against a FrankenPHP in worker mode. To keep as a load test in Phase 3.

### (c) Is the response size sustainable?

**The format option is INERT.** The first draft concluded "possible per
operation" on the strength of how the container was built. A real call proved it wrong.

The plan wanted to switch the envelope to plain JSON, JSON-LD's `@context`/`@id` being
noise in an agent's context window. **Both routes were tried, and
neither works:**

| Attempt | Result |
|---|---|
| `api_platform.mcp.format = 'json'` alone | **Rejected**: "The MCP format "json" is not configured in api_platform.formats." |
| `json` registered in `api_platform.formats` + global `mcp.format = 'json'` | Accepted, parameter verified as `json` in the container, cache cleared - **output still JSON-LD** |
| `new McpTool(..., outputFormats: ['json' => ['application/json']])` | Accepted at construction - **output still JSON-LD** |

Actual response of a tool call, `content[0].text` **and** `structuredContent`:

```json
{"result":{"content":[{"type":"text","text":"{\"@context\":\"/contexts/TripDetail\",\"@type\":\"TripDetail\",…"}],
 "isError":false,
 "structuredContent":{"@context":"/contexts/TripDetail","@type":"TripDetail",…}}}
```

**Conclusion: the `@context`/`@type` envelope is currently unavoidable** on an MCP tool
output in API Platform 4.3.19. The noise per response is modest, but it cannot be
avoided, and the configuration option that claims to fix it does nothing.

**What remains true from the first draft**: declaring a format on a `McpTool`
**does not affect** the HTTP operations - the OpenAPI exports with and without the `mcp:` block
are byte-for-byte identical (431,992 bytes, `cmp` silent). The MCP bucket is
indeed sealed; it is simply that nothing in it consumes the format.

### (d) How do you functionally test an MCP tool?

**`debug:mcp` works perfectly.** A first draft of this verdict concluded there was
a tooling blind spot: that was wrong, and the real explanation is much better.

`debug:mcp` reported "No MCP capabilities are registered". It was neither a stale
cache (`cache:clear` changes nothing) nor a wiring defect - the decoration is
correct, `mcp.server.btp.registry` does resolve to
`ApiPlatform\Mcp\Capability\Registry\SecureRegistry`, and `DebugCommand::listElements()`
does call `$registry->getTools()` on it.

**It was the security filter doing its job.** In CLI no user is
authenticated, so the tool's `is_granted('ROLE_USER')` expression was false and
`SecureRegistry` hid it from the listing - exactly
`testToolDeniedBySecurityIsOmittedFromGetTools`. Removing the expression makes the
tool appear immediately:

```text
Tools (1)
  Name       Handler                      Description
  get_trip   api_platform.mcp.handler()   Read one bikepacking trip: ...
```

**So it is positive proof**, not a setback: security filtering at
listing time works end to end for a tool declared via `mcp:` on an `ApiResource`,
which until then had only been inferred from unit tests.

Practical consequence worth knowing: **`debug:mcp` only shows the tools visible to
the current user**, and in CLI that is the anonymous user. A tool missing from the output is not
necessarily badly registered - it may simply be denied. The feedback remains usable,
provided the list is read as a filtered view.

The underlying question - a PHPUnit functional test of a tool call - **remains open**:
it will require emitting JSON-RPC on `POST /mcp`, or testing the underlying operations.

### (e) Is `league/oauth2-server` compatible with Symfony 8?

**Not settled - the spike stopped before that**, the discoveries in (a) having changed the
question. Since the SDK provides the resource server and a delegation proxy, the choice
is no longer "league or by hand" but "which AS behind the proxy". To be revisited with
that framing.

---

## The most important finding, which the plan had not seen

### The project's `security:` expressions are not portable to an MCP tool

Declaring the tool with the exact expression of the equivalent HTTP operation:

```php
security: "is_granted('TRIP_VIEW', request.attributes.get('id'))"
```

makes the container fail:

```text
Unable to get property "attributes" of non-object "request".
```

**An MCP tool is evaluated without an HTTP request**, hence without a `request` variable in the
expression context.

Two lessons:

1. **`security:` IS indeed evaluated on an MCP tool** - the expression runs, which is
   what makes it break. The plan was right in substance: a tool is an
   API Platform operation and goes through the same chain of access checkers.
2. **But none of the project's expressions can be reused as-is.** `Trip.php`,
   `Stage.php`, `TripDetail.php`, `TripRoute.php`, `MercureToken.php` and
   `AccommodationScan.php` all write their object authorization in the form
   `request.attributes.get('id')` or `request.attributes.get('tripId')`.

The plan asserted that "a tool reuses the existing `security:` unchanged". **That is
false** - but there is a portable form, and it is designed for exactly this.

### The portable form: `object`, and deferral to call time is deliberate

`ApiPlatform\Mcp\Security\ExpressionAccessChecker::isGranted()` passes
`['request' => $this->requestStack?->getCurrentRequest()]` - hence the `null` in CLI. But
its `catch (SyntaxError)` carries this comment, which answers the question directly:

> The expression reads variables that only exist once the element is called (object,
> previous_object, uri variables). Listing cannot decide, so the element stays visible and
> the expression is enforced on tools/call and resources/read, as AccessCheckerProvider
> already defers the pre_read stage in that case.

In other words **API Platform deliberately designed the deferral to call time**: an expression
referencing `object` cannot be decided at listing time, raises a `SyntaxError`, which is
caught, the element **stays visible**, and the expression is enforced on `tools/call`.

**Verified**: `is_granted('TRIP_VIEW', object)` and then `is_granted('TRIP_VIEW', object.id)`
both leave the tool listed, whereas the `request.attributes.get('id')` form made
the command crash.

**`object.id` and not `object`**: `TripVoter::supports()` (l. 48-52) only accepts a
`TripRequest` or a **string**, not this DTO. Passing `object.id` therefore avoids touching the
voter.

### Why the crash is not caught - a real upstream defect

The `catch` only covers `SyntaxError`, i.e. an **undefined** variable. But
`request` is **defined but null**: `request.attributes` is therefore a property-access error
at runtime (`GetAttrNode`), not a syntax error - it escapes the
`catch` and brings the command down.

This is defensible as a **report to the core team**: when `getCurrentRequest()` returns
`null`, it would be better not to inject the `request` variable at all (which
would produce a `SyntaxError`, hence the graceful degradation already planned) rather than
inject it as `null` and let any expression that dereferences it blow up.

### The refactor, and its exact scope

`is_granted('TRIP_VIEW', object.id)` works **for the HTTP operation as well as for
the tool**. The proposed refactor is therefore consistent and causes no contract regression.

**Measured scope: 18 `security:` expressions referencing `request.`**, spread over
7 files - `Trip.php`, `Stage.php`, `TripDetail.php`, `TripRoute.php`,
`MercureToken.php`, `AccommodationScan.php` and `Entity/TripShare.php`.

**And the migration has already started**: out of 24 object-authorization expressions,
**6 already use bare `object`**, all in `Trip.php` (l. 84, 100, 117, 133, 142, 161).
Their providers return a `TripRequest`, which `TripVoter::supports()` accepts.

**A security nuance not to lose.** `request.attributes.get('id')` is evaluated **before**
the provider, `object.id` **after**. The denial does happen, but **the provider's side
effects have already happened**. For three cases that is a database read, harmless. For
`MercureTokenProvider` it would mean **minting a JWT before denying** - which is why
that case switches to the URI variable and not to `object`.

### Two-stage security semantics, clarified

`SecureRegistry` (`api-platform/mcp`) filters the **listing**: a denied tool disappears from
`tools/list`. But its test `testGetToolStillReturnsReferenceForToolDeniedBySecurity`
shows that **`getTool()` still returns the reference** when security denies.

Actual enforcement therefore happens at call time, through the provider chain decorated in
`Bundle/Resources/config/mcp/security.php` - the same `AccessCheckerProvider` and the same
`api_platform.security.resource_access_checker` as the HTTP operations, at all four stages
(`pre_read`, read, `post_denormalize`, security parameter).

**The two stages are distinct and both necessary.** A functional test per tool
remains mandatory in Phase 3: listing filtering does not prove enforcement at
call time.

---

## Other frictions encountered, all real

- **No route is created by the recipe.** `symfony/mcp-bundle` provides a
  `Routing\RouteLoader` answering `supports($r, 'mcp')`, but its auto-generated recipe
  does **not** add the routes import: the only file created under `config/` was
  `http_discovery.yaml`. Without a hand-written `config/routes/mcp.php`, the HTTP
  transport is configured and **no `/mcp` route exists** - `debug:router` shows nothing
  and the server is silently unreachable. Once the import is added:
  `_mcp_endpoint_btp   GET|POST|DELETE|OPTIONS   /mcp`.
- **The config-transformer trap is real but displaced.** The recipe did not generate
  `mcp.yaml`, but it did write `config/packages/http_discovery.yaml` (via
  `php-http/discovery`). The project's "everything in PHP" convention requires converting it.
- **DNS-rebinding protection active by default.** An undefined `http.allowed_hosts`
  restricts the server to `localhost`: exposing a public server requires listing the
  hosts, or `false` to disable it. Not something to discover in production.

---

## What this changes for the plan

| Plan item | After the spike |
|---|---|
| "Check whether `api-platform/mcp` provides the resource server" | **Provided by `mcp/sdk`**, and **reachable** by decorating `mcp.server.<name>.middleware_factory`. Phase 3A shrinks for good. |
| "Do not write the AS by hand, build on `league/oauth2-server`" | **Confirmed by the SDK's own ADR**, which recommends it by name. |
| "A tool reuses the existing `security:` unchanged" | **False.** A **three**-branch recipe, decided by what the provider returns: **6 already on `object`**, **3 → `object.id`**, **15 → URI variable**. |
| "`security:` is enforced at call time" | **PROVEN** by functional test: owner OK, other user `Access Denied.`, anonymous 401. |
| "An ownership denial comes out as *not found* (ADR-038)" | **FALSE - a security hole.** `Access Denied.` vs `... not found.` are distinguishable: UUID enumeration reopened on the MCP path. |
| "How to test an MCP tool" | **SOLVED**: `ApiTestCase` + JSON-RPC on the route, no MCP client. Mirror headers mandatory. |
| "No dependency conflict" | Correct, but **8 transitive packages** added. |
| "`format: json` to avoid the JSON-LD noise" | **Impossible - the option is inert.** Global and per-operation tested, output still JSON-LD. The MCP bucket stays sealed on the HTTP side (identical OpenAPI diff). |
| "`debug:mcp` as the first feedback loop" | **Usable** - the apparent absence of tools was the security filter in an anonymous context. |
| "The FrankenPHP worker mode is handled by `McpRegistryPass`" | **Confirmed by design**, not verified under load. |
| Experimental dependencies | **No conflict** on PHP 8.5 / Symfony 8.1 / API Platform 4.3. |

## Second pass - what the critical review proved, and corrected

The first draft inferred a lot from reading code. A real functional test written on the spike
branch (`api/tests/Functional/McpToolCallTest.php`, 4 green tests; the file of that name on
`main` is the later test of the shipped server, not this one) settled it.

### ✅ PROVEN: `security:` is enforced at call time, not only at listing time

This is the plan's central claim, and until then it was only a deduction. Two
authenticated users, a trip belonging to the first:

| Caller | Response |
|---|---|
| Owner | The full trip, title included |
| Other user | `{"jsonrpc":"2.0","id":1,"error":{"code":-32603,"message":"Access Denied."}}` |
| Anonymous | **401** - the `api` firewall (`pattern: ^/`) covers `/mcp` without a single line of config |

The positive test asserts the title, so the denial test **cannot pass vacuously**.

### ✅ SOLVED: how to functionally test an MCP tool

**No MCP client is needed.** The transport is an ordinary Symfony route:
`ApiTestCase` posts JSON-RPC to it as to any endpoint, with Foundry for the
database. This is the answer to question (d), left open in the first pass.

**The envelope requires mirror headers**, discovered one by one through `-32020` errors
(HeaderMismatch): `MCP-Protocol-Version`, then `Mcp-Method`, then `Mcp-Name`. The JSON-RPC
body is not enough - the 2026-07-28 revision duplicates version, method and element name
in headers, which makes it possible to route or cache a call at the edge without parsing the body.

> **Completed by unit 3C (25/09/2026)**: true of the modern path only, the one a
> call chooses by carrying the `_meta` claim. The SDK also serves the "handshake" era,
> which validates no mirror header and accepts batches, and it unwraps a base64-encoded
> `Mcp-Name` before comparing it. These headers are therefore not an input a
> guard can decide on: a `trips:read` token reached `delete_trip` through both paths.

### 🔴 SECURITY HOLE: ADR-038 does not hold on the MCP path

The plan asserted that an ownership denial would come out as "not found", preserving
ADR-038. **That is false, verified:**

```text
someone else's trip : Access Denied.
nonexistent trip    : Trip "01936f6e-0000-7000-8000-0000000009ff" not found.
```

Two **distinguishable** responses: the MCP path leaks the existence of a trip, which is
exactly the UUID enumeration that ADR-038 closes on the HTTP side.
`HideForbiddenAsNotFoundListener` is an HTTP kernel exception listener; the SDK
catches the exception and converts it into a JSON-RPC error without the listener ever stepping in.

**To be handled in Phase 3**, and it is not optional: the masking must be reproduced on
the MCP path, or ADR-038 must state that it does not cover this transport.

### ⚠️ CORRECTED: `object.id` is NOT the universal recipe

Actual breakdown of the 18 expressions:

| Form | Count |
|---|---|
| `is_granted('TRIP_EDIT', request.attributes.get('tripId'))` | **12** |
| `is_granted('TRIP_VIEW', request.attributes.get('id'))` | 4 |
| `is_granted('TRIP_VIEW', request.attributes.get('tripId'))` | 2 |

**14 out of 18 are about `tripId`, a *parent* identifier**, not the object's id. And
`AccessCheckerProvider` only provides `object`, `previous_object` and `request` - **no
`uriVariables`** - so `uriVariables['tripId']` cannot be written.

**And `object.id` does not even cover the other 4.** The real criterion is not what the
operation is keyed on, but **what its provider returns**:

| The provider returns… | Form | Count |
|---|---|---|
| a `TripRequest` (accepted by `TripVoter::supports()`) | bare `object` | already done (6) |
| a DTO exposing the id - `Trip`, `TripRoute`, `TripDetail` | `object.id` | **3** |
| something else, or a parent key | URI variable | **15** |

The fifteenth is `MercureToken.php`: keyed on its own `id`, but its provider returns a
DTO whose only property is `$token`. There is **no `id` to read**, so `object.id` is
impossible there.

**The right answer is URI-variable security**, carried by
`SecurityParameterProvider` (present in the MCP chain as
`api_platform.mcp.state_provider.security_parameter`): each URI variable can carry its
own expression, evaluated with its bound value **under its own name**. `Link` accepts
`security` and `securityObjectName`, and `Stage.php` already declares its `uriVariables`.

```php
uriVariables: [
    'tripId' => new Link(fromClass: Stage::class, security: "is_granted('TRIP_EDIT', tripId)"),
    'index' => new Link(toProperty: 'dayNumber', fromClass: Stage::class),
],
```

Final recipe: **3 expressions → `object.id`**; **15 → on the URI variable**;
**6 already migrated** to bare `object`, nothing to do there.

> **Trap to flag at security review**: `SecurityParameterProvider` does
> `continue` if `$targetResource` cannot be found (`getFromClass() ?? getToClass()`). A
> misconfigured `Link` therefore **skips the check silently**.

### ⚠️ CORRECTED: `uriVariables` must be declared explicitly on the tool

Without it, the tool's argument never reaches the provider: the call runs and dies
on `Trip "" not found.` - a silent failure on the mapping side, not a visible
configuration error.

### ⚠️ CORRECTED: "no conflict" understated the cost

No *version* conflict, but **8 transitive packages** added: `opis/json-schema`,
`opis/string`, `opis/uri`, `php-http/discovery`, `psr/http-client`,
`psr/http-server-handler`, `psr/http-server-middleware`, `psr/simple-cache`. That is a
real expansion of the dependency surface, to run through the project's `security-check`.

### ✅ SOLVED: the SDK's OAuth resource server is reachable through the bundle

The first draft left this point open, and the whole lightening of Phase 3A
depended on it. It is resolved: `McpBundle.php:399` registers
**`mcp.server.<name>.middleware_factory`** as a container service (verified:
`mcp.server.btp.middleware_factory` exists), `McpController` injects it, and its `create()`
feeds `new StreamableHttpTransport(...)` - whose constructor **accepts a custom
middleware stack** (`?iterable $middleware`, `null` installing the defaults).

Plugging in the SDK's `AuthorizationMiddleware` and `ProtectedResourceMetadataMiddleware` is
therefore **a standard Symfony decoration of that service**, with no fork or patch.

### What remains unproven

- **FrankenPHP in worker mode under concurrent load.** Handled by design
  (`McpRegistryPass`), never exercised.
- **FrankenPHP in worker mode under concurrent load** - the only point truly not
  covered. The risk is documented and handled by `McpRegistryPass`, and lazy loading
  of the registry was verified in a fresh process; what remains to exercise is
  the multi-request case of a persistent runtime. That belongs to the load test already
  planned for Phase 3, not to a spike.

---

## Sweep of dependencies on `Request` (requested after the first draft)

Since `request` does not exist at MCP listing time, what else depends on it?

- **18 `security:` expressions referencing `request.`**, over 7 files - that is the
  scope of the refactor to `object.id`.
- **6 services inject `RequestStack`.** Four are out of MCP scope by design
  (`AuthSessionProvider`, `AuthRequestLinkProcessor`, `AccessRequestCreateProcessor`,
  `RequestEmailChangeProcessor` - all of `/auth/*` and early access are excluded from
  the tools).
- The two that matter for the Phase 3 write path, **`TripCreateProcessor:69` and
  `TripUpdateProcessor:64`**, only use it for
  `getCurrentRequest()?->getPreferredLanguage(['en','fr']) ?? 'en'`, in order to store the
  trip's locale. **This is not a crash but a silent degradation**: a tool call
  does arrive through `POST /mcp`, so `getCurrentRequest()` is not null, but an
  agent generally does not send an `Accept-Language` - every trip created via MCP would therefore be
  in `en`.

No other dependency on `Request` was found in the providers and mappers.

### The locale: two uses to separate

The pipeline runs in a worker, without a request: the locale must therefore be captured at
creation. **At least 8 handlers** read it back (`AnalyzeTerrain`, `ScanAccommodations`,
`CheckCulturalPois`, `FetchWeather`, `ResolveStageLabels`, `CheckHealthServices`,
`CheckRailwayStations`, `CheckBorderCrossing`) via
`$this->tripStateManager->getLocale($tripId) ?? 'en'`. But they do not do the same thing with it.

| Use | Where | Does it leave the `Request` scope? |
|---|---|---|
| **Rendering** - alert messages and action labels, translated then **persisted as strings** (`RestDayNudgeAnalyzer:97`, `EbikeRangeAnalyzer:66`: `$translator->trans('alert.…', [], 'alerts', $locale)`) | Analyzers | **Yes - to be removed**, see below |
| **Acquisition** - language passed to a third party: reverse geocoding (`ResolveStageLabels`), DataTourisme descriptions (`CheckCulturalPois`, `ScanAccommodations`) | Handlers | **No** - it is an outgoing request parameter, it must stay at write time |

**Immediate fix, free, to do in any case**: `User` **already** carries a
locale (`User.php:118`, exposed by `AccountMeProvider`, already used by
`AuthRequestLinkProcessor` and `RequestEmailChangeProcessor`). `TripCreateProcessor` and
`TripUpdateProcessor` must read `$user->getLocale()` instead of the HTTP header. This
**removes `RequestStack` from both processors**, works identically over HTTP and MCP,
and is more correct anyway: a saved preference is better than a browser
header.

**Underlying fix, aligned with the plan's guiding principle** - *persist the facts,
derive the verdicts at read time*. A translated message **is a rendering, not a fact**:
persist `code` + structured parameters, render at read. The project already has the identity it
needs - every alert carries a stable `AlertCode`, and the frontend already relies on it for
dedup and dismiss.

For an agent, this is **strictly better than a translation**: serving `SUNSET_RISK` +
`{arrivalTime, sunsetTime}` lets it phrase things in the actual language of its
conversation, which the server cannot know. A string pre-translated into `fr`
rendered to an agent answering in English is a defect, not a convenience.

**Consequence for lot B of the plan**: "persist the published payload verbatim" must be
read as *verbatim minus the rendering* - keep `code`, `type`, coordinates and parameters,
stop freezing `message` and `action.label`.

**Recommendation**: proceed. The transport, discovery, two-stage security and
the resource server are acquired or provided. The real cost of Phase 3 is concentrated on
two items the spike isolated - re-encoding the authorization expressions, and
the authorization server - and not on the protocol.
