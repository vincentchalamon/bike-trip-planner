---
name: sprint
description: Implement all issues from a sprint in parallel via worktree agents
argument-hint: <sprint-number>
---

Implement all issues from sprint `$ARGUMENTS` in parallel using worktree agents. Parse the sprint number from arguments.

If no sprint number is provided, ask the user for it before proceeding.

## Step 0 — Validate input

Check that the sprint number is a valid number. If not, ask the user for a valid sprint number.

## Step 1 — Parse TRACKING.md and build dependency graph

Read `TRACKING.md` and extract the table for the requested sprint. For each issue, extract:
- Issue number
- Title
- Status (skip issues already marked as "Done" or with a PR link)
- Dependencies ("Dépend de" column or similar)

Build a DAG from the dependencies and compute **waves** (topological layers):
- **Wave 1**: issues with no dependencies
- **Wave 2**: issues depending only on Wave 1 issues
- **Wave N**: issues depending only on issues from previous waves

If a circular dependency is detected, report it and stop.

## Step 2 — Fetch all issues in parallel

Use the Agent tool to fetch all issue bodies in parallel (up to 3 concurrent agents). Each agent runs `gh issue view <number>` and returns the issue body. Collect all results before proceeding.

Alternatively, fetch issues sequentially if the count is small (≤5).

## Step 3 — Phase 1: Code in parallel (worktree agents)

For each wave, in order, launch worktree agents to implement each issue. **Maximum 3 concurrent agents per batch** (Agent tool limitation). If a wave has more than 3 issues, split into batches of 3.

Each agent receives this prompt:

```
You are implementing GitHub issue #<number>: <title>

## Issue body
<full issue body from Step 2>

## Instructions
1. Read CLAUDE.md to understand the project architecture and conventions
2. Create branch `feature/<issue-number>` from `<base-branch>`
   - Base branch is `main` unless this issue depends on another, then use `feature/<dep-number>`
3. Implement the solution following CLAUDE.md rules (architecture, SOLID, patterns)
4. Commit your changes using Conventional Commits format
5. **IMPORTANT: Do NOT run any `make` commands** — no `make qa`, `make test`, `make typegen`, `make phpunit`, `make phpstan`, etc. The main agent handles QA/tests later.
6. If you modify backend DTOs (api/src/ApiResource/), include "DTO_CHANGED" in your final message
7. If you add new dependencies to composer.json or package.json, include "DEPS_CHANGED" in your final message
8. Focus only on writing correct, well-structured code and committing it
```

Use `isolation: "worktree"` for each agent.

**Dependency handling:**
- If an issue depends on another issue from a previous wave, its agent branches from `feature/<dep-number>` instead of `main`
- If a dependency's agent failed, mark the dependent issue as **BLOCKED** and skip it

Track results: for each issue, record SUCCESS (with worktree branch), DTO_CHANGED, DEPS_CHANGED, FAILED, or BLOCKED.

## Step 4 — Phase 2: QA and tests (sequential, Docker shared)

For each successfully coded branch, **in dependency order**:

1. `git checkout feature/<issue-number>`
2. If DEPS_CHANGED: `make install`
3. If DTO_CHANGED: `make typegen`
4. Run `make qa` — if it fails, read errors, fix, commit, retry (up to 3 attempts)
5. Run `make test` — if it fails, read errors, fix, commit, retry (up to 3 attempts)
6. If QA/tests still fail after 3 attempts, mark as **FAILED** and continue to the next branch

## Step 5 — Phase 3: Push and create PRs

For each branch that passed Phase 2, create a PR:

1. `git push -u origin feature/<issue-number>`
2. Create the PR with `gh pr create`:
   - Title: Conventional Commit format matching the issue type
   - Body: summary + Auto-critique section per CLAUDE.md
   - Base branch: `main` (or `feature/<dep-number>` for dependent issues)
3. Run `gh pr checks --watch` to wait for CI (run multiple watches in parallel if possible)

If CI fails, read logs, fix, push, and re-check (up to 2 attempts).

## Step 6 — Update TRACKING.md

For each issue, update the TRACKING.md row:
- SUCCESS with PR: set status to "En cours", add PR link and branch name
- FAILED: set status to "Échoué"
- BLOCKED: set status to "Bloqué"

Commit and push the TRACKING.md update.

## Step 7 — Final report

Display a summary table:

```
| Issue | Title | Status | PR | Notes |
|-------|-------|--------|----|-------|
| #42   | Add X | ✅ PR  | #50 | CI passing |
| #43   | Fix Y | ❌ FAILED | — | QA: PHPStan error in Foo.php |
| #44   | Add Z | 🚫 BLOCKED | — | Depends on #43 |
```

Include timing information if available (total duration, per-phase breakdown).
