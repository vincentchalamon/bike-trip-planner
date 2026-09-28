# Spike — `league/oauth2-server` as the MCP authorization server (23/09/2026)

> **Written verdict of a time-boxed investigation.** It ran on the throwaway branch
> `spike/oauth2-server-feasibility`, never merged and deleted once this document was brought
> onto `main` (28/09/2026). It became the context of
> [ADR-079](../adr/adr-079-authorizing-an-agent-without-giving-it-a-session.md), which carries
> the decision. The findings below were checked by running them, not inferred from the
> documentation; where they were not, it says so.

## Overall verdict

**Adopted.** The reservation that blocked phase 3A ("Symfony 8 compatibility unconfirmed,
single point of failure") **is lifted by measurement**: the bundle resolves and boots on
Symfony 8.1 / PHP 8.5 / API Platform 5.0, on a **stable** release, not a development branch.
The "reduced scope" fallback planned in case the library did not fit does not need to be
invoked.

What remains to be written is **smaller than the plan assumed on three points, and unchanged
on two**. No blocker was found.

## What the measurement corrects in the phase 3 plan

| The plan says | The measurement says |
|---|---|
| "`league/oauth2-server` declares `~8.5.0` at library level" | Version numbers were mixed up: `8.5` was a **library version**, not a PHP constraint. The current **9.4.1** declares `php ~8.2.0 \|\| ~8.3.0 \|\| ~8.4.0 \|\| ~8.5.0`, so PHP 8.5 is explicitly supported |
| "Symfony 8 compatibility unconfirmed (docs: 6.4+; active branch 2.x)" | **Confirmed, on stable.** `league/oauth2-server-bundle` **v1.2.2** declares `symfony/framework-bundle: ^6.4\|^7.4\|^8.0`, and the same for `security-bundle` and `psr-http-message-bridge`. 2.x-dev exists but is not needed |
| "a **PSR-7** bridge to set up in a Symfony 8 application" | **Nothing to write**: `symfony/psr-http-message-bridge` is a declared dependency of the bundle, and `nyholm/psr7` comes with it |
| "CIMDs must be grafted onto a `ClientRepositoryInterface` that assumes **stored** clients" | Correct, and the cost is visible: the bundle ships Doctrine entities `Client`/`AccessToken`/`RefreshToken`/`AuthorizationCode` and seven client-management commands. A custom `ClientRepositoryInterface` that resolves a URL still has to be written |
| "a **second token system** next to Lexik + `RefreshToken`" | Correct, and **the tables do not clash**: the mapping driver prefixes everything with `oauth2_` (`Persistence/Mapping/Driver.php:28`). Two lifecycles, yes; a schema collision, no |
| "RFC 8707 audience binding is not native" | **Confirmed**: no occurrence of `resource` in the RFC 8707 sense in either the library or the bundle. It stays custom, as planned |

## Installation: ten packages, no conflict

`composer require league/oauth2-server-bundle` on `main` at `2bfd35ee`:

| Package | Version |
|---|---|
| `league/oauth2-server-bundle` | **v1.2.2** (stable) |
| `league/oauth2-server` | **9.4.1** |
| `league/event` | 3.0.3 |
| `league/uri` / `league/uri-interfaces` | 7.8.1 |
| `lcobucci/jwt`, `defuse/php-encryption` | v2.4.0 |
| `nyholm/psr7` | 1.8.2 |
| `psr/http-server-handler`, `psr/http-server-middleware` | 1.0.2 |

No platform override, no security advisory, no constraint conflict. **The kernel boots**:
`cache:warmup -e dev` passes, and `debug:router` lists the bundle's three routes. Resolving is
not working; both were checked.

## What the bundle provides, and what it does not

**Provided, not to be rewritten:**

- **PKCE S256 required for public clients by default**:
  `AuthCodeGrant.php:54`, `requireCodeChallengeForPublicClients = true`. The bundle exposes
  the opposite switch (`LeagueOAuth2ServerExtension.php:301`): never flip it.
- **Refresh rotation**: `RefreshTokenGrant.php:81-82`, native `revokeRefreshTokens`.
- **The authorization code, the token exchange, the device code**, and the three routes that
  go with them.
- **The persistence entities and seven client-management commands**, including
  `ClearExpiredTokensCommand` and `GenerateKeyPairCommand`.
- **The consent hook**: `AuthorizationRequestResolveEvent`. The bundle provides no screen, it
  provides the event, which is exactly what the plan needs, since the decision comes from a PWA
  page already authenticated by the BFF.

**Missing, so to be written:**

- **The RFC 9728 and RFC 8414 metadata.** No occurrence of `.well-known`,
  `oauth-authorization-server` or `oauth-protected-resource` in the bundle, the library,
  **or `api-platform/`**. Note that `api-platform/mcp` is not installed on `main` (it only ever
  was on the step 1 spike branch), so "if `api-platform/mcp` does not provide them" is to be
  reconfirmed when installing it.
- **RFC 8707 audience binding.** Confirmed missing from both packages. It is MCP's central
  security requirement and it is entirely custom.
- **Client ID Metadata Documents**, on a custom `ClientRepositoryInterface`. Fetching a client
  URL is an SSRF vector and must go through the ADR-011 guard.
- **The consent screen** itself.
- **Per-user revocation.** The bundle purges by expiry, not by account. The "account deleted,
  so agents revoked" requirement does have to be implemented a second time, as the plan feared.

## Two mounting traps, measured

1. **The routes are mounted at the root**: `/authorize`, `/token`, `/device-code` (the
   bundle's `config/routes.php`), not under `/oauth/`. The import must set a prefix, otherwise
   the phase 3A firewall plan (`^/oauth/` as `PUBLIC_ACCESS` before the catch-all) covers
   nothing. And since the `api` firewall has `pattern: ^/`, these routes fall into it by
   default: firewall declaration order is indeed the sensitive point the plan announced.
2. **The recipe writes YAML** (`config/packages/league_oauth2_server.yaml`,
   `config/routes/league_oauth2_server.yaml`), while the repository only has PHP config and QA
   checks "No YAML configs found". To be converted when mounting.

## Answer to the question the spike had to settle

> *What really remains to be written, and is the impedance worth the state machine gained?*

**Yes.** What remains is a thin, well-bounded layer: two metadata documents, a
`ClientRepositoryInterface` that resolves a URL, audience binding, a consent screen, and
per-account revocation. What is gained is the complete OAuth state machine with mandatory PKCE
and refresh rotation, plus client persistence and tooling, on a stable, compatible dependency.

The announced impedance turned out smaller than feared on three of its five points: the PSR-7
bridge is not work at all, compatibility is confirmed, and the second token system does not
collide with the existing schema. The two remaining points (RFC 8707 and the double revocation)
would have existed with any implementation, hand-written ones included.

## What this spike did **not** check

Not to be presented as settled:

- **No flow was run end to end.** The bundle boots and routes; no `/authorize` → `/token` was
  played, no token issued, no scope enforced.
- **Behaviour under the FrankenPHP worker** was not exercised, although that is precisely where
  the MCP registry needed special handling.
- **The two firewalls living side by side** (Lexik PWA session and `mcp`) were not mounted; the
  plan describes it, the spike did not put it to the test.
- **No performance or load measurement.**

These four points belong to the design of 3A, not to the feasibility question this spike had
to settle.
