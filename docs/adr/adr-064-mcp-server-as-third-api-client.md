# ADR-064: MCP Server as a Third API Client

- **Status:** Accepted (feasibility); **implemented** — see the amendment of 24/09/2026 at the end
- **Date:** 2026-09-18
- **Depends on:** ADR-001 (Global Architecture), ADR-023 (Authentication Strategy), ADR-038 (Hide Forbidden As Not Found), ADR-043 (Synchronous Structural Computation), ADR-057 (Progressive Trip Loading), ADR-063 (Transport-Agnostic Authorization)
- **Relates to:** ADR-052 (Remove AI Support) — this is not a reversal, see below
- **Evidence:** [MCP feasibility spike, 17/09/2026](../spikes/mcp-feasibility-2026-09-17.md)

## Context and Problem Statement

The product has two clients of its API: the Next.js PWA and the Expo mobile app. A user
who wants to plan a trip and then consult it — accommodation, resupply, weather, alerts —
without opening either one has no way in.

ADR-052 removed AI from the product on 11/08/2026 because **nobody can pay for
inference**, and because the surface it required (a worker, a Messenger transport, DB
columns, an encrypted credential store) was disproportionate. An MCP server inverts that
equation: **the inference lives in the client**, and the operator exposes nothing but
tools over the API that already exists. No model, no per-token bill, no user credential to
store.

This is therefore **not a reversal of ADR-052**. An MCP server is not an AI feature; it is
a **third client of the API**, of a different nature than a UI. The API has been
client-agnostic since ADR-023, and ADR-056 already had to open the Mercure subscriber
token to non-browser clients for the mobile app.

A timeboxed spike was run before committing. This ADR records what it established.

## Decision

**Adopt MCP as a third client, built on API Platform's native integration.** Implementation
is deferred; this ADR fixes the architecture and the known constraints so the work can be
scoped.

### 1. The integration is native, and a tool is an API Platform operation

`ApiPlatform\Metadata\McpTool` and `McpResource` extend `HttpOperation` and are declared
through the `mcp:` parameter of an existing `#[ApiResource]`. They reuse the operation's
provider, processor, validation and serialization unchanged.

Packages, installed together without a version conflict on PHP 8.5 / Symfony 8.1 /
API Platform 4.3: `api-platform/mcp` v4.3.19, `mcp/sdk` v0.8.1, `symfony/mcp-bundle`
v0.13.0, plus `psr/simple-cache` for the `cache` session store that FrankenPHP's worker
mode requires. **Eight transitive packages** come with them (the `opis/*` trio and the
PSR-7/15 stack); they are accepted and go through the project's `security-check`.

These packages are **experimental and pre-1.0**, outside Symfony's BC promise. That is a
deliberate exception to the project's dependency-ceiling policy, taken on 17/09/2026,
justified by the isolation of the surface: MCP metadata lives in a separate bucket and
touches nothing else (see §5).

### 2. Authorization is enforced, and it is the same code path as HTTP

`security:` on a tool is enforced **at call time**, proven end to end: the owner receives
the trip, a second authenticated user receives
`{"code":-32603,"message":"Access Denied."}`, an anonymous caller receives **401** from
the existing `api` firewall (`pattern: ^/`) with no configuration added.

Two distinct layers, both needed: `SecureRegistry` filters `tools/list` so a caller does
not see what they cannot use, and the provider chain
(`api_platform.mcp.state_provider.*`, decorated by the same `AccessCheckerProvider` as
HTTP) enforces on the call. Filtering is not enforcement: `getTool()` still returns a
denied tool's reference.

Expressions must follow ADR-063 — `request.` does not exist here.

### 3. BTP is an OAuth 2.1 Resource Server, never an Authorization Server

`mcp/sdk` ships the Resource Server complete: RFC 9728 Protected Resource Metadata,
`AuthorizationMiddleware`, `JwtTokenValidator`, `JwksProvider`, OIDC discovery, and an
`OAuthProxyMiddleware` that delegates `/authorize` and `/token` upstream.

It is **reachable**: `symfony/mcp-bundle` registers
`mcp.server.<name>.middleware_factory` as a container service which `McpController`
injects and whose output feeds `StreamableHttpTransport`, and that constructor accepts a
custom middleware stack. Wiring the SDK's OAuth middleware is a standard Symfony
decoration — no fork.

Token issuance stays out. The SDK's own ADR-0001 declines authorization-server pull
requests and names the alternative: *"Run `league/oauth2-server` in your own application,
behind the SDK's existing proxy and validator seams."* This project adopts that posture.
Which authorization server sits behind the proxy is left to a later ADR.

### 4. ADR-038 does not hold on this transport — and that is a gap to close

Measured on the MCP path: another user's trip answers `Access Denied.`, an unknown id
answers `Trip "…" not found.`. The two are **distinguishable**, so trip UUIDs are
enumerable here even though ADR-038 closes that on HTTP.

The cause is layering, recorded in ADR-063: the masking is implemented by an HTTP-kernel
exception listener, and the SDK converts the exception to a JSON-RPC error without it.

**Reproducing the masking on the MCP path is a prerequisite of shipping write tools**, and
a functional test asserting that both cases answer identically is part of the definition
of done.

### 5. What MCP does not touch

Tools live in a `mcp` bucket separate from `operations:`. Proven, not assumed: the OpenAPI
exports with and without the `mcp:` block are **byte-for-byte identical**. `core/schema.d.ts`,
the CI drift guard and the `pwa` / `mobile` builds are unaffected.

### 6. What is deliberately excluded from the tool surface

The dividing line is **interaction versus information**, not web versus agent.

| Excluded | Why |
|---|---|
| The map | Pan, zoom, clicking a pin, dragging a marker — the *gesture* has no textual equivalent. The data still passes; `share_trip` hands the human a URL. |
| `POST /trips/gpx-upload` | Multipart, 30 MB. Creation is by source URL only — an accepted functional restriction. |
| `POST /trips/{id}/nearby-pois` | Needs a live GPS fix; ADR-048 built it deterministic and offline-friendly for exactly the opposite use case. |
| `/auth/*`, the consent screen | Email and browser bound by design. |
| Push / device tokens | Device-scoped. |
| `DELETE /users/me` | Irreversible anonymisation; never an agent tool. |
| Mercure live streaming | `subscriptions/listen` would hold one long connection per agent, incompatible with the stateless model and FrankenPHP workers. Progress notifications cover the need. |

## Amendment, 24/09/2026 — what shipping it changed

Recorded rather than rewritten: this document is the decision as it was taken, and two of its
measurements have since been overtaken by the code.

**The implementation is no longer deferred.** Unit 3A (#1307) built the authorization server
and mounted `/mcp` behind it; unit 3B put the read tools on it.

**§4 named reproducing ADR-038's masking as a prerequisite of shipping write tools. It is
done, and proved per tool rather than once.** `McpIndistinguishabilityTest` asserts for every
tool taking an identifier that someone else's record and a record that does not exist answer
identically, byte for byte, and that the owner still gets through — without which two answers
failing for a trivial reason would compare equal and prove nothing. What makes it hold is the
shape of the expression: naming the URI variable evaluates it at `pre_read`, before any
provider can report absence. `McpToolContractTest` refuses a tool that authorizes through
`object`, which is the form that reopens the oracle.

**"The `@context` / `@type` envelope is unavoidable" was right for the wrong reason, and
understated the cost.** `api_platform.mcp.format` is no longer inert: the upstream fix
(api-platform/core #8542) ships in v5.0.0 and does reach every MCP operation's formats. The
envelope survives anyway because `StructuredContentProcessor` normalizes with the format of
the `POST /mcp` request, which is never the operation's.

And it costs more than a couple of extra keys. Measured on this transport: **every array
property is rendered as a Hydra `Collection` carrying its values only, so an associative array
arrives with its keys stripped** — `{"route": "done", "weather": "running"}` leaves as
`{"member": ["done", "running"]}`, and the reader is told two statuses with no idea what
either describes. That is data loss. **Nothing in a tool's answer may be keyed by data.** The
payload is also duplicated: `content[0].text` repeats `structuredContent` verbatim, so
everything is paid for twice.

## Consequences

### Known limitations, measured

- **The `@context` / `@type` envelope is unavoidable.** `api_platform.mcp.format` is
  inert: setting it globally (with the format registered and the cache cleared) and
  declaring `outputFormats` on the tool both leave the output as JSON-LD, in
  `content[0].text` and in `structuredContent` alike.
- **`uriVariables` must be declared explicitly on the tool**, or its argument never
  reaches the provider and the call dies on `Trip "" not found.` — a silent mapping
  failure, not a configuration error.
- **The 2026-07-28 envelope mirrors protocol version, method and element name into
  headers** (`MCP-Protocol-Version`, `Mcp-Method`, `Mcp-Name`); each omission is a
  `-32020` HeaderMismatch. This lets an edge route or cache a call without parsing the
  body.
- **The Flex recipe creates no routing import.** Without a hand-written
  `config/routes/mcp.php` importing the bundle's `mcp` loader, the HTTP transport is
  configured and **no `/mcp` route exists** — silently.
- **DNS-rebinding protection defaults to localhost only**; a publicly reachable server
  must list its hosts.

### Testing

No MCP client is required. The transport is a plain Symfony route, so `ApiTestCase` posts
JSON-RPC at it like any other endpoint, with Foundry for the database. `debug:mcp` is
usable but shows only what the **current** user may see — anonymous in CLI — so an absent
tool may be denied rather than unregistered.

### Not established

FrankenPHP worker mode under concurrent load. `McpRegistryPass` documents and handles the
stale-registry risk, and lazy loading was verified in a fresh process; the multi-request
persistent-runtime case belongs to a load test, not a spike.

## Alternatives considered

**A separate Node MCP server consuming the public API.** Rejected: it would duplicate the
authorization rules outside the voters — the one place duplication is least acceptable —
whereas the native integration reuses them by construction.

**Writing the Resource Server by hand.** Rejected: `mcp/sdk` ships it, and credential
handling is the last surface this project should own.

**Waiting for the packages to reach 1.0.** Rejected on the evidence that the MCP bucket is
watertight: a breaking change upstream cannot reach the REST contract, the generated types
or the two existing clients.
