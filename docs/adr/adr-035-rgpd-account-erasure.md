# ADR-035: GDPR Account Erasure and Data Portability

- **Status:** Accepted
- **Date:** 2026-05-31
- **Depends on:** ADR-022 (Persistent Storage Strategy), ADR-023 (Authentication — Passwordless Magic Link)
- **Related:** Sprint 34, #549

## Context and Problem Statement

Once accounts exist (magic-link sign-in, ADR-023), the application stores personal data: the
user's email address and their saved trips (configuration, stages, chat history, shares, per-trip
preferences). The GDPR grants every user the right to erasure (Art. 17) and the right to data
portability (Art. 20), and these must be self-service — a user must be able to leave, and to take
their data with them, without manual intervention.

The questions this ADR settles:

1. On erasure, do we hard-delete the user row or anonymise it?
2. Is erasure immediate, or deferred to a scheduled purge?
3. What does the portable export contain, and is it produced synchronously?

## Decision Drivers

- **Compliance:** erasure must sever the PII link and remove the user's content; portability must
  return the data in a structured, machine-readable format.
- **Immediacy:** users expect "delete my account" to take effect now, not "within 30 days".
- **Operational simplicity:** the zero-budget ops model favours no extra moving parts (no purge
  queue, no cron, no background reconciliation).
- **Referential integrity:** the deletion must not leave dangling foreign keys or require fragile
  manual cleanup.

## Decision

Expose two authenticated self-service operations on the current user (API Platform custom
operations under `App\ApiResource\Account\Account`):

### `DELETE /users/me` — right to erasure (immediate, irreversible)

Handled by `AccountDeleteProcessor` inside a single transaction:

1. **Purge trips** — a bulk delete of the user's `TripRequest` rows; foreign keys cascade
   (`ON DELETE CASCADE`) to stages, chat messages, shares and per-trip preferences. Trips carry
   the bulk of personal and route data, so they are physically removed.
2. **Revoke sessions** — all refresh tokens for the user are removed, so no lingering session can
   be reused.
3. **Anonymise the account** — `User::anonymize()` stamps `deletedAt` (soft-delete) and
   irreversibly replaces the email with a non-routable, unique placeholder, breaking the PII link.
4. The response is `204 No Content` and clears the refresh-token cookie.

The user **row is anonymised rather than hard-deleted**: trips (the personal payload) are
physically purged, while the now-anonymous account row is retained so the operation stays simple
and referentially safe. Erasure is **immediate and irreversible** — there is no recovery window
and **no purge cron**.

### `GET /users/me/export` — right to portability (synchronous)

Handled by `AccountExportProvider`: returns a JSON archive (`Content-Disposition: attachment`)
containing the profile (id, email, locale, roles, creation date), every trip with its
preferences, and each trip's stages. Produced synchronously — per-user volumes are small.

## Alternatives Considered

### Hard-delete the user row

Physically delete the `User` row alongside the trips.

**Rejected:** offers no compliance benefit over anonymisation once the email is scrubbed and
trips are purged, while making referential integrity more fragile (any future table referencing
`user_id` would need careful cascade rules). Anonymise-in-place is simpler and equally compliant.

### Deferred purge (soft-delete now, scheduled hard-delete later)

Mark the account deleted and let a nightly cron physically remove data after a grace period.

**Rejected:** adds a scheduled job and a reconciliation surface for no user benefit; a grace
window also means PII lingers longer than necessary. Immediate erasure is simpler and stronger.

### Soft-delete without anonymisation

Stamp `deletedAt` but keep the email.

**Rejected:** the email is PII; keeping it would defeat the purpose of erasure.

### Asynchronous export (Messenger + email a download link)

**Rejected for now:** unnecessary for individual-account volumes; a synchronous response is
simpler and immediate. Can be revisited if exports ever grow large.

## Consequences

### Positive

- Self-service erasure and portability satisfy GDPR Art. 17 and Art. 20.
- Erasure is immediate and irreversible; no background job to operate or monitor.
- Trips (the bulk of personal data) are physically removed; the residual account row carries no
  PII.
- The whole erasure runs in one transaction — no partially-deleted state.

### Negative

- An anonymised `User` row persists (id, `deletedAt`, placeholder email). This is a deliberate,
  PII-free residue, not full row removal.
- Erasure is irreversible by design — a user who deletes by mistake cannot recover their trips.
- The export shape is hand-maintained in `AccountExportProvider`; new persisted fields must be
  added there to remain complete.

### Neutral

- The anonymised email uses a unique, non-routable placeholder, so the original address can never
  be matched and the unique constraint is preserved.
- The user-facing wording lives on the in-app `/privacy` page; see also
  [docs/legal-and-licensing.md](../legal-and-licensing.md).

## References

- `api/src/ApiResource/Account/Account.php` — operation definitions
- `api/src/State/Account/AccountDeleteProcessor.php` — erasure
- `api/src/State/Account/AccountExportProvider.php` — portability export
- [ADR-022: Persistent Storage Strategy](adr-022-persistent-storage-strategy.md)
- [ADR-023: Authentication Strategy](adr-023-authentication-strategy.md)
