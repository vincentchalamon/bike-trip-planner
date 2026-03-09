---
name: review
description: Deep review of a pull request (security, performance, documentation)
argument-hint: <pr-number>
allowed-tools: Bash(gh *), Bash(git *), Bash(make *), Read, Glob, Grep
---

Deep review of pull request `$ARGUMENTS`. Parse the argument as the PR number.

If no PR number is provided, ask the user for it before proceeding.

Execute these review steps and produce a structured report:

## Step 1 -- Fetch PR context

Run `gh pr view <pr-number>` and `gh pr diff <pr-number>` to get the full diff and PR metadata. Read the list of commits with `gh pr view <pr-number> --json commits`.

## Step 2 -- Conventional Commits (PR title only)

Verify the **PR title** follows `<type>(<scope>): <description>`:
- Valid types: `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `build`, `ci`, `chore`, `revert`
- Description must be imperative mood, lowercase, no trailing period
- Do NOT check individual commit messages (commits are squashed on merge)
- Flag if the PR title is non-conforming

## Step 3 -- Diff analysis

Scan the diff for:
- Leftover `console.log`, `dump()`, `dd()`, or debug statements
- Stale TODO/FIXME comments (must be resolved or tracked in a ticket)
- Dead code: unused methods, unreachable branches, orphaned imports
- Unintended technical debt

## Step 4 -- Security review

Review every changed file for OWASP Top 10 vulnerabilities:
- Injection, XSS, SSRF, XXE, path traversal, insecure deserialization
- Flag any new user input that is not validated
- Check HTTP client scoping and XML parsing flags per Security Constraints in CLAUDE.md

## Step 5 -- Performance review

Look for:
- N+1 queries, unbounded loops, missing pagination
- Large memory allocations, missing caching opportunities
- Blocking I/O in async paths

## Step 6 -- Architecture compliance

Verify per CLAUDE.md:
- Project architecture respected (stateless backend, local-first frontend, DTO contract)
- SOLID principles and Law of Demeter followed (deviations documented in comments)
- Design patterns used where appropriate (no unjustified quick and dirty)

## Step 7 -- Documentation check

- Verify PHPDoc/JSDoc on modified public APIs
- Check if ADRs need updating
- Verify TRACKING.md is consistent

## Step 8 -- Test coverage

Check that new/changed behavior has corresponding tests:
- Unit tests (PHPUnit)
- E2E tests (Playwright)
- Flag untested edge cases

## Step 9 -- Auto-critique section

Verify the PR body contains an Auto-critique section listing what was verified.

## Step 10 -- Report

Use the **Review Comment Format** section in CLAUDE.md for all findings (Conventional Comments labels).

Produce a structured review report with:
- Findings use Conventional Comments labels: `issue (blocking)`, `issue`, `suggestion (non-blocking)`, `nitpick (non-blocking)`, `praise`
- Specific `file:line` references for each finding
- Findings grouped by category (Security, Performance, Architecture, Documentation, Tests, Commits)
- End with the CLAUDE.md review checklist, each item checked or unchecked based on findings:
  - [ ] Code respects the project architecture
  - [ ] SOLID principles and Law of Demeter followed
  - [ ] Design patterns used where appropriate
  - [ ] No dead code (unused methods, unreachable branches, orphaned imports)
  - [ ] No lingering TODO/FIXME comments
  - [ ] Tests cover new/changed cases
  - [ ] Documentation is up to date
  - [ ] Dependent tickets accounted for
