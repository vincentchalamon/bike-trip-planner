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

## Step 6 — Phase 4: Drive each PR to READY

For each open PR, run the **bounded surveillance loop** until it converges. **Maximum K=3 cycles per PR.** Never merge — the user merges (a PR is only ever brought to READY).

Each cycle:

1. **CI** — `gh pr checks <pr>`. If red: read the failing logs (`gh run view --log-failed`), apply the smallest fix, commit, push.
2. **Review comments** — fetch both:
   - PR-level comments: `gh pr view <pr> --json comments`
   - Inline review comments: `gh api repos/:owner/:repo/pulls/<pr>/comments`

   Address every **actionable** point, commit, push, then reply to / resolve the corresponding threads. List any comment you deliberately did not action, with the reason.
3. **Conflicts** — `gh pr view <pr> --json mergeable,mergeStateStatus`. If `CONFLICTING`: rebase onto the base branch and resolve **conservatively**. If the resolution is ambiguous or risks discarding work, **stop and flag it** — do not force a resolution.

**Termination (READY)** when all hold: CI green **AND** `mergeable` **AND** not draft **AND** `claude-code-review.yml` has completed (`gh run view --workflow=claude-code-review.yml` shows `completed`) with **no new blocking comment** (Critical/High). Wait for that workflow to finish before evaluating its output — after a push it is triggered asynchronously and may still be `pending`/`in_progress`.

**Loop note:** each push triggers `synchronize` → the review bot re-runs and auto-resolves its own fixed threads. Treat that auto-resolution as progress; stop as soon as a cycle produces no new blocking comment. After K cycles without convergence, mark the PR **NEEDS ATTENTION** with the blocking reason and move on.

## Step 7 — Update TRACKING.md

For each issue, update the TRACKING.md row:
- READY PR: set status to "En cours", add PR link and branch name
- NEEDS ATTENTION: set status to "En cours", add PR link, note the blocker
- FAILED: set status to "Échoué"
- BLOCKED: set status to "Bloqué"

Commit and push the TRACKING.md update.

## Step 8 — Final report

Display a summary table:

```
| Issue | Title | Status | PR | Notes |
|-------|-------|--------|----|-------|
| #42   | Add X | ✅ READY | #50 | CI green, no blocking review |
| #43   | Fix Y | ⚠️ NEEDS ATTENTION | #51 | Conflict on Foo.php, flagged |
| #44   | Add Z | ❌ FAILED | — | QA: PHPStan error in Bar.php |
| #45   | Add W | 🚫 BLOCKED | — | Depends on #44 |
```

Include timing information if available (total duration, per-phase breakdown).
