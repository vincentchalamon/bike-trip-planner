# ADR-079 — Authorizing an agent without giving it a session

**Status:** accepted
**Date:** 2026-09-24
**Supersedes in part** [ADR-023](adr-023-authentication-strategy.md), which decided that this
API authenticates people with a magic link and a short JWT. That stays true for people. It is
not what an agent needs, and this records why a second mechanism exists rather than one.
**Amends** [ADR-038](adr-038-hide-forbidden-as-not-found.md) on one path, and only on one:
`/mcp` has a refusal that must be visible.
**Continues** [ADR-063](adr-063-transport-agnostic-authorization.md) and
[ADR-064](adr-064-mcp-server-as-third-api-client.md).

## Context

An MCP server is a third client of this API, not an AI feature. A third client needs a way to
be trusted, and the one this application already has is not transferable: the PWA and the
mobile app carry a JWT minted after a magic link, scoped to nothing, valid for everything the
person can do. Handing that to a program running on someone's laptop is not delegation, it is
impersonation.

The MCP specification answers with OAuth 2.1, and requires a handful of things this
application had none of: protected resource metadata, authorization server metadata, PKCE,
audience-bound tokens, and a registration mechanism for clients nobody has ever met.

Two constraints shaped everything below. There is no session — `framework.session` is
`false`, the single firewall is stateless, and the only browser credential in the deployment
is the httpOnly cookie the Next.js BFF sets (ADR-047). And there is no production: a break in
contract costs nothing today and will never be this cheap again.

## Decision

### The authorization server is `league/oauth2-server`, and the impedance is smaller than feared

Its own spike settled the compatibility question by measurement. What this unit adds on top is
a thin, bounded layer: two discovery documents, a client resolver, an audience, a consent
screen. The state machine — authorization codes, PKCE verification, refresh rotation, token
persistence — is the library's, and reimplementing it was never on the table.

Four things the bundle does NOT do, and that are written here so nobody re-derives them:

- it emits no `iss` on the authorization response (RFC 9207);
- it never looks at the audience — its validator reads `aud[0]` as a client identifier;
- its client identifier column is `VARCHAR(32)`, which no Client ID Metadata Document URL
  fits;
- its own Flex recipe points the OAuth signing keys at Lexik's keypair.

### Two token systems, deliberately

A session token and an agent access token are the same shape, from the same issuer, signed
with the same algorithm. What tells them apart is **the key**. That is a cryptographic
separation rather than a claim to be checked, and it is why the two are not merged:

- with one issuer, the boundary becomes a value in a payload, and a session token carrying
  `trips:write` would be indistinguishable from an agent's;
- the bespoke refresh path is stricter than league's — it detects replay and revokes the whole
  family, with a grace window and encryption at rest;
- OAuth 2.1 offers no grant for "this person is already authenticated by a magic link". The
  password and implicit grants are gone, so converging would mean either a custom grant —
  writing OAuth by hand — or running a full authorization code flow from the BFF to itself on
  every login.

Because the separation is configuration rather than code, the prod entrypoint compares the two
public keys and **refuses to boot** when they match. A functional test asserts the other half:
a valid PWA session JWT gets 401 on `/mcp`.

The price is duplication — two keypairs, two refresh rotations, two revocation paths, two
token tables — and it is accepted with its own follow-up rather than hidden.

### The consent screen borrows the BFF's credential, and the decision is recorded server-side

The bundle requires an authenticated user on `/authorize` and throws a 500 otherwise. With no
session, the only browser credential available is the refresh cookie, read exactly as
`GET /auth/session` already reads it: looked up, never rotated, no `Set-Cookie`. It is mounted
on one firewall matching one path, so it does not become a general-purpose API credential.

The endpoint is crossed twice. The first pass parks the request and sends the browser to a
page in the PWA; the second completes it. **Nothing is added to the returning URL**: the
handle is derived from the request itself, so the second pass recomputes it — and a client
that changes an argument in between simply finds no decision and is asked again. A signed
ticket in a query string would have been a credential in a URL, in the browser history and in
the access log.

The decision is recorded by a POST carrying the session Bearer, and that POST is the
anti-CSRF of the whole flow: the return leg is a top-level GET with a SameSite=Lax cookie,
which any page can trigger, and it only completes because a decision is already on file.

The screen shows what the server resolved, never the query string, and treats the client's
name as third-party text: escaped, on its own line, never inside a sentence. `logo_uri` is not
rendered at all — it would load an attacker-chosen image from our page. A client whose
callbacks are all loopback addresses gets a warning, because nothing can prove a loopback
address belongs to the application you think it does.

### An authorization request that names no scope is refused

`scopes.default` is not a per-request default: the bundle stamps it onto a client that
declares none, and league resolves an empty request against that list at FINALIZE time, on the
way to issuing the token — after the consent screen, which would have displayed nothing.
Consenting to an empty list and receiving permissions afterwards is the one outcome no screen
can make honest, so the request is refused with `invalid_scope` instead.

### Authorization is ownership AND scope

`TripVoter` remains the authority. A scope narrows what an agent may do with the trips its
user owns; it never grants access to anyone else's. The scopes are `trips:read` and
`trips:write`, and they are account-wide: there is no per-trip consent, and none is planned.

### A missing scope is said out loud; a missing right is not

Two refusals must not leave as the same answer. A caller lacking a permission gets **403 with
`insufficient_scope`** and the scope it needs, or it can never ask for the right thing. A
caller asking about someone else's trip gets exactly what a trip that does not exist would
give, or the endpoint becomes a UUID oracle.

The check runs **before** the MCP server rather than on `kernel.exception`, because that is
where it can run: an `AccessDeniedException` from a tool's expression is caught inside the MCP
SDK and returned as a JSON-RPC error, never reaching the kernel's exception path. Deciding
first also means a call with the wrong scope runs no provider — which is what will matter when
tools start writing.

*Amended in unit 3C.* Deciding before the server meant deciding on the `Mcp-Name` header, and
that turned out not to be enforcement: the SDK unwraps an encoded name before checking it,
and serves a handshake era that checks no mirror header and accepts batches. A read-only
token reached `delete_trip` all three ways. The authority is now a decorator of the MCP
handler, which judges each message the SDK has parsed; the listener keeps the one thing only
`kernel.request` can do — answer the well-formed call with 403 and `WWW-Authenticate`.

Each tool declares the scope it consumes, once, on itself. The map is read back from the
metadata and a CI guard refuses a tool that declares none.

### A token names the resource it is for

`aud` carries the client first — league's validator reads `aud[0]` as the client identifier,
and `permittedFor()` appends, so the order is load-bearing — then the canonical URI of the MCP
server. The `resource` parameter is checked on both endpoints; a value that is not ours is
`invalid_target`.

With one resource and one issuer this is belt to the braces of a dedicated key. It stops being
redundant the moment a second protected resource exists, which is when forgetting it would
cost the most. No multi-resource machinery is built, because there is no second resource to
build it against.

### Clients register by naming a document, and that fetch is the sharpest edge in the unit

Dynamic registration is deprecated and not implemented. A client names itself by the HTTPS URL
its metadata document is served from — which makes this the only place in the application
where the **host** of an outbound request is chosen by a third party. The existing SSRF
pattern does not transpose: every other call goes through a client locked to a `base_uri`,
with a numeric id from an anchored regex dropped into a fixed path.

What replaces it:

- the URL is judged before anything is opened — https, a path component, no fragment, no
  credentials, not our own host, short enough to store;
- the transport follows no redirect, gives up quickly on inactivity and on total duration, and
  is wrapped in `NoPrivateNetworkHttpClient`, which refuses on the IP actually connected to
  rather than on a name resolved beforehand;
- the body is read through a ceiling on the transfer, not read and then measured;
- the document must name itself — its `client_id` must equal the URL it came from;
- a redirect URI is HTTPS or a literal loopback address. `http://localhost` is refused
  although league would match it: it resolves through DNS, and league's loopback rule covers
  only the literal addresses;
- nothing reports why a resolution failed. Every refusal is `invalid_client`, and the
  exception carries no detail either, because a transport failure quotes the address it
  blocked.

And it runs on `/oauth/authorize` only. `/oauth/token` is `PUBLIC_ACCESS`; resolving there
would put an outbound request to a caller-named host behind no authentication at all.

### Deleting an account revokes its agents

`revokeCredentialsForUser()` runs in the erasure transaction, **before** `anonymize()`. The
revoker filters on the email, which `anonymize()` rewrites: called afterwards, its four
UPDATEs succeed and touch zero rows. `DeletedUserChecker` on the `mcp` firewall is the other
half, and neither replaces the other.

## Consequences

Accepted, and written down rather than discovered:

- **No "authorized applications" screen.** A user cannot see or revoke a grant they gave. A
  grant also survives logging out: logout revokes refresh tokens, not OAuth grants. This is
  the most visible gap and the natural next unit.
- **No per-trip consent.** `trips:read` reaches every trip the account owns.
- **CIMD has no domain trust policy.** The specification allows one (MAY). Nothing here
  protects against a public address fronting an internal service, or against a metadata host
  that is simply hostile — only against the private network and against a document that lies
  about its own identity.
- **An email longer than 128 characters cannot be granted to an application.** The bundle's
  `user_identifier` column is `VARCHAR(128)` and this application stores 180. The
  authorization endpoint refuses such an account up front rather than letting the token
  endpoint fail on an INSERT with the browser already gone.
- **A logged-out user loses the authorization request.** The entry point sends them to the
  login page with no return target, because authentication crosses an email and the PWA's own
  gate already drops deep links. The agent has to ask again from a signed-in browser.
- **Duplication between the two token systems.** Key generation and rotation, revocation on
  account deletion, expiry: three mechanisms that exist twice. A follow-up covers sharing the
  tooling without merging the issuers.
- **The device code grant and client credentials are off.** Neither is used by MCP, and a
  client-credentials token carries no user, which is a shape no voter here was written for.

## Alternatives considered

**Writing the authorization server by hand.** Rejected before the spike and confirmed after
it: PKCE, exact redirect matching, refresh rotation, code single-use and the error contract
are a lot of surface to get subtly wrong, and none of it is this product's problem.

**Hashing the client URL into the bundle's 32-character column.** Rejected: league looks a
client up by the identifier the request sent, in three places, and one missed translation
point would not fail — it would match another row.

**Putting the resource in a custom claim instead of `aud`.** Rejected: `aud` is what a
resource server is expected to check, and the ordering problem it raised turned out to be one
line.

**Signing a consent ticket into the return URL.** Rejected: a credential in a query string
ends up in browser history and access logs, and the programme already forbids tokens in URLs.
The server-side record is both safer and simpler, since the handle can be derived rather than
carried.

**Moving the PWA and the mobile app onto OAuth too.** Rejected for now, with reasons above.
Revisiting it is a unit of its own, not a paragraph in this one.
