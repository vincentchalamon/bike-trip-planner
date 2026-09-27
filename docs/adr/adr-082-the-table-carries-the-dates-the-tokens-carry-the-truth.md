# ADR-082 — The table carries the dates, the tokens carry the truth

**Status:** accepted
**Date:** 2026-09-27
**Continues** [ADR-079](adr-079-authorizing-an-agent-without-giving-it-a-session.md), which
closed on the gap it had left open: a consent screen with no way back.
**Related** [ADR-081](adr-081-what-an-agent-can-make-the-server-believe-say-and-do.md) §6, on
what bounds an agent that has been let in.

## Context

ADR-079 built a door an agent walks through once. Nothing showed the user what was behind it
afterwards, and nothing took the access back. The only revocation in the codebase was account
deletion, and `CredentialsRevokerInterface::revokeCredentialsForClient()` — the method the
ticket prescribed — was called nowhere.

That was tenable while the door was being built. It stopped being tenable as a resting state,
because the scopes are **account-wide**: `trips:read` reaches every trip of the user, there is
no per-trip consent and none is planned (ADR-079, restated in ADR-081 §6). The user's only
lever is which application holds access — so they have to be able to see the list, and to
shorten it.

Two facts about the existing schema shaped everything below.

**The token tables cannot say when.** `oauth2_access_token` carries
`identifier, expiry, user_identifier, scopes, revoked, client`; `oauth2_refresh_token` carries
`identifier, expiry, revoked, access_token`. One temporal column each, and it points forward.
Access tokens live fifteen minutes and refresh tokens rotate, so a single authorization leaves
dozens of rows behind, none of which knows the day the user said yes.

**The user identifier in those tables is an email address.** It is what the token carries and
what the provider loads by, which makes an email change a silent mass revocation (§5).

## Decision

### 1. Two records, one invariant, stated as a rule

A durable `oauth_grant` row records what the tokens cannot: `authorized_at`, `last_used_at`,
and the scopes as granted. The tokens keep deciding what they already decide: whether the
application can still act.

The rule that binds them, and the title of this ADR:

> **The table carries the dates, the tokens carry the truth.**

Concretely, the account screen lists a grant **only while a live, unrevoked token still backs
it**. A row on its own proves nothing; it never did. Nothing sweeps the table, so after a month
of inactivity a row would otherwise claim an access that expired weeks ago.

This is the invariant between the two records, not a join to be optimised away later. Any
future mechanism that revokes tokens without knowing about grants leaves the screen lying, and
that is the failure mode to look for first when it does.

**The filter includes refresh tokens, not only access tokens.** An access token lives fifteen
minutes; a filter that looked at them alone would make every application vanish a quarter of an
hour after its last call and reappear on the next refresh.

### 2. The grant is keyed on the user entity, the tokens on the address

`oauth_grant.user_id` is a foreign key to `user`, where the bundle's tables carry
`user_identifier`, an email address. An address changes; a person does not. That difference is
what lets a grant survive an email change — the user still sees their applications and can
re-authorize them — and it is also why the revocation that must accompany one has to be written
by hand (§5).

### 3. The row is written at token issuance, and nowhere else

The obvious seat is a trap and two plausible ones are dead:

- `AccessTokenManagerInterface::save()` is **also the revocation hook**
  (`AccessTokenRepository:77` and `:90`), and a refresh calls both in sequence
  (`RefreshTokenGrant:80` then `:86`);
- `ACCESS_TOKEN_EXTRA_CLAIMS_RESOLVE` is **dead here**: our own
  `AudienceBoundAccessTokenRepository::getNewToken()` replaces the method that dispatches it
  (ADR-079), so a listener would never run;
- `TOKEN_REQUEST_RESOLVE` carries neither user nor client.

The write therefore sits in `AudienceBoundAccessTokenRepository::persistNewAccessToken()`, a
method we already own, called once per token issued and never on revocation.

Three properties of that write are load-bearing:

- **it is a DBAL upsert, never `persist()`/`flush()`.** The token is already flushed at that
  point, so a unique-constraint violation would close the EntityManager and answer 500 to an
  agent whose token is live in the database;
- **the conflict resolves to `DO UPDATE SET scopes`, not `DO NOTHING`.** A second authorization
  that widens the scopes (`trips:read` → `+ trips:write`) would otherwise leave the original
  row untouched and the screen would **under-declare** what the application may do — the worst
  direction for a screen whose purpose is informed revocation. `authorized_at` is kept: that is
  the day the user opened the door, and showing a later one would hide it;
- **a user it cannot resolve is logged, not raised.** Everything on this path is paid for in
  failed token issuance, i.e. agents that stop connecting.

### 4. Revocation is a tombstone, and it intersects user and client

`revoked_at` is stamped; the row is not deleted. `RefreshTokenGrant` checks revocation (`:128`),
revokes the old token (`:80-83`), then issues (`:86`). A revocation committing inside that
window lets the refresh complete, and §3's write would recreate a deleted row with a brand-new
`authorized_at` — the application the user just cut off, listed as freshly authorized. A
**partial** unique index (`(user_id, client_identifier) WHERE revoked_at IS NULL`) leaves room
for a genuine re-authorization beside the tombstone; a total unique index would force a choice
between losing the history and refusing the re-authorization.

The token side is written by hand in `GrantRevoker`, and **not** with
`revokeCredentialsForClient()`. That method filters on the client alone
(`DoctrineCredentialsRevoker:80-131`): called from one user's account page it would cut the
application off for **every** user of it. The intersection covers access tokens, the refresh
tokens hanging off them — `oauth2_refresh_token` has no client column, so the client goes in the
subquery — and authorization codes. A test with two users sharing a client asserts the
difference, rather than a comment asking future readers to be careful.

Account deletion is the exception: it **deletes** the rows. The tombstone defends a refresh
race inside a live account; it has nothing to defend after anonymisation, and a row linking an
anonymised account to third-party applications has no reason to survive.

### 5. Changing an email address revokes, explicitly

`VerifyEmailChangeProcessor` set the new address and flushed. The tokens carry the old address
as their user identifier and the provider loads by it, so every live agent token answered 401
afterwards with nothing to explain it — a silent mass revocation, one file away from the
comment in `AccountDeleteProcessor` that warns about exactly this ordering.

The revocation is now explicit and ordered **before** the change. This is not a consequence of
the feature; the feature made it visible.

### 6. What counts as use

`last_used_at` is written by `McpGrantUsage`, a third handler decorator placed **inside**
`McpCallBudget`, so only a call that was allowed and paid for counts. One decorator, one
subject: the budget's contract is what a call may cost, and a class that also wrote an
unrelated column would be one nobody could reason about later. `McpHandlerChainTest` pins the
full order — scope, budget, usage, tools — with the reason for each position.

A **tool call or a resource read** is use. `initialize` and `tools/list` are not: an agent that
connects, lists and leaves reads as *never used*. The screen answers "what has this application
done with the access", not "has it ever held a connection".

Three constraints on the write, all about not harming the call:

- it runs **after** the inner handler. At that depth the SDK owns the HTTP response, so a
  failure here could not be reported anyway, and a write before the call could poison the
  transaction the tool opens;
- it **lets no exception out**. The call succeeded; a missing timestamp is a wrong line on a
  screen, not a failed edit;
- it **never reads the database to decide whether to write**. A handshake-era batch carries up
  to a hundred messages (ADR-081 §1), and a SELECT per message would trade one write for a
  hundred reads. A cache key per grant, five minutes, decides instead — in a pool that stays on
  Redis under test, like `cache.oauth_consent`, or the test that it does not write twice inside
  the window would prove nothing.

Granularity follows: "last used" is as durable as that cache, so the screens say *20 Sept.*,
never a time of day.

### 7. The screens show the host, because the name is the attacker's

`oauth2_client.name` comes from the client's own metadata document, is written once at first
authorization and never refreshed (`ClientIdMetadataDocumentListener:72-77`). A screen showing
only that name is a phishing surface: "Bike Trip Planner Officiel" looks like us, on the page
where a user decides whether to cut an access.

The **host of the `client_id`** is the only part of a client's identity it cannot choose. Both
are shown, on their own lines, on web and on mobile — and the name is **never interpolated into
a sentence**, including in the confirmation dialog, so it cannot borrow the authority of our
own wording. This is the rule ADR-081 §4 states for error messages, applied to a screen.

The item operations are addressed by the **grant's** uuid, never the client's URL, which has no
business in a path or in an access log.

### 8. Revoking asks for confirmation, not for a typed word

Account deletion makes the user type `SUPPRIMER` because it cannot be undone. Revoking can: the
user re-authorizes from the agent. A plain confirmation on both platforms — `DestructiveDialog`
without a keyword on the web, `Alert.alert` on mobile — and a mistake in the safe direction
costs one re-authorization.

## Consequences

- A user can see and cut every application that holds access to their account, from either
  client, without deleting the account.
- The account screen stops being a place where the only exit is the nuclear one.
- One more table takes part in the OAuth flow, and one more write sits on the token-issuance
  path. §3 is why that write cannot fail the issuance.
- The rule in §1 is now something a future change has to honour or explicitly break.

## Known limits, assumed

- **The two records can still disagree, and the filter is the only thing holding them
  together.** A mechanism that revokes tokens without stamping `revoked_at` leaves a row that
  the filter will hide rather than correct — right on the screen, wrong in the table. That is
  the intended failure direction, not an invariant.
- **A refresh token orphaned by the bundle's expiry sweep is unreachable from any revocation.**
  `clearExpired()` sets `refresh_token.access_token` to NULL (`ON DELETE SET NULL`) before
  deleting expired access tokens, so a refresh token with weeks of life left stops being
  reachable through the subquery every revocation uses — including account deletion today — and
  invisible to §1's filter. **Nothing schedules that sweep**: our own
  `PurgeExpiredTokensCommand` only touches PWA session tokens and magic links. This becomes
  blocking the day someone schedules `league:oauth2-server:clear-expired-tokens`, and it is a
  fix to make before scheduling it, not after.
- **`last_used_at` is throttled and therefore approximate**, by five minutes, and is lost if the
  Redis pool is flushed between the call and the write. §6.
- **There is no notification when an application is authorized.** The consent screen is the only
  moment the user is told, and this screen is the only place they can check afterwards.
- **Nothing expires a grant for inactivity.** The row survives; the tokens do not, and §1's
  filter is what makes that invisible.
- **The mobile screen has no maquette.** It follows the components and spacing of its
  neighbours, which is a convention and not a design decision.
