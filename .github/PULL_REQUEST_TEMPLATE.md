<!-- markdownlint-disable MD041 -->

## Summary

<!-- What does this PR do and why? -->

## Changes

-

## Bug fix

<!-- Fill this section only if the PR fixes a bug. -->

- [ ] Linked GlitchTip event ID and related incident issue (if applicable)

## Test plan

- [ ] `make qa` passes
- [ ] New/updated tests cover changed behavior
- [ ] Manual verification (describe below)

## Verification

- [ ] Related runbook updated if applicable
- [ ] If this PR introduces or removes a secret, `docs/runbooks/secrets-inventory.md` is updated

## Auto-critique

- [ ] No leftover `console.log`, `dump()`, `dd()`, or debug statements
- [ ] No stale TODO/FIXME comments (resolved or tracked in a ticket)
- [ ] No dead code (unused methods, unreachable branches, orphaned imports)
- [ ] Code respects the project architecture (stateless backend, local-first frontend, DTO contract)
- [ ] SOLID principles and Law of Demeter are followed
- [ ] Documentation (PHPDoc, JSDoc) is up to date for modified public APIs
- [ ] If backend DTOs changed: `schema.d.ts` is regenerated (`make typegen`)

<!-- claude-review-start -->
<!-- claude-review-end -->
