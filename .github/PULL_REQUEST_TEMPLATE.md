<!-- markdownlint-disable MD041 -->

## Summary

<!-- What does this PR do and why? -->

## Changes

-

## Test plan

- [ ] `make qa` passes
- [ ] New/updated tests cover changed behavior
- [ ] Manual verification (describe below)

## Auto-critique

- [ ] No leftover `console.log`, `dump()`, `dd()`, or debug statements
- [ ] No stale TODO/FIXME comments
- [ ] Code respects the project architecture (stateless backend, local-first frontend, DTO contract)
- [ ] SOLID principles and Law of Demeter are followed
- [ ] Documentation (PHPDoc, JSDoc) is up to date for modified public APIs
- [ ] If backend DTOs changed: `schema.d.ts` is regenerated (`make typegen`)
