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

## Step 2b — Map file overlap between issues (do not skip)

The `Dépend de` column models **issue** dependencies only. It says nothing about two independent issues editing the same file, which is where the real cost of a parallel sprint lands: at merge time, on the user.

Before launching any agent, build an overlap map from the issue bodies fetched in Step 2 — their "Fichiers impactés" sections, plus any file path named in the Scope. Group by file and keep every file claimed by **two or more** issues.

For each overlapping file, decide and record:
- **which change must win** if the two are semantically incompatible (a deletion beating an extension is the common case);
- whether the diffs can be kept **disjoint by construction** (e.g. one issue rewrites a SQL predicate, another adds two columns to the same `SELECT`).

Then **inject the overlap into each agent's prompt** — name the sibling issue, the shared file, and the instruction to keep the diff surgical. Observed in Sprint 44: the branches that received this warning produced trivial conflicts; the arbitration written up-front (#861's deletion of `detectMissingSurfaceData()` beating #860's extension of the same class) was exactly what the rebases needed four merges later.

Carry the map into Step 7 so `TRACKING.md` records the expected conflicts and the merge order **before** the user starts merging.

Sanity check: if one file is claimed by four or more issues, say so and ask the user whether those issues should be serialised into one wave instead of run in parallel. Four branches editing `SurfaceAlertAnalyzer.php` cost four rounds of conflict resolution in Sprint 44.

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
3. Bootstrap deps in the worktree by hard-linking from the main repo (the worktree starts with empty vendor/ and node_modules/; reinstalling per worktree is expensive and Docker compose spins up a separate project per worktree path):
   ```bash
   MAIN=$(git worktree list | awk 'NR==1{print $1}')
   for p in api/vendor api/vendor-bin provisioner/vendor provisioner/vendor-bin pwa/node_modules; do
     [ -d "$MAIN/$p" ] && rsync -a --link-dest="$MAIN/$p/" "$MAIN/$p/" "$p/"
   done
   ```
4. Implement the solution following CLAUDE.md rules (architecture, SOLID, patterns)
5. **`make qa` will NOT complete — do not spend turns on it.** Read the "Local QA" section of CLAUDE.md and run the legs individually with the container recipes given there: the `php` service is capped at 768M in `compose.yaml` and Rector's parallel workers get OOM-killed (exit 137), and a worktree's `pwa_node_modules` volume is empty so `make qa-pwa` reports `eslint: not found`. Every leg must be green individually — Rector especially, because a skipped autofix fails CI in dry-run mode and costs a round-trip (observed Sprint 34.5: #580 PHP 8.4 `new` without parentheses, #585 `NewlineAfterStatementRector`). Commit autofixes (PHP-CS-Fixer, Rector, Prettier). PHPStan / TypeScript / ESLint errors must be fixed by hand. **Do NOT run `make test` / `make install`.**
   - Report the per-leg output verbatim in your final message. Do not write "make qa passes" — it cannot; say which legs you ran and what they printed.
6. Commit your changes using Conventional Commits format (final commit must include any QA autofixes — never leave a dirty worktree)
7. If you modify backend DTOs (api/src/ApiResource/), include "DTO_CHANGED" in your final message
8. If you add new dependencies to composer.json or package.json, include "DEPS_CHANGED" in your final message
9. Focus only on writing correct, well-structured code and committing it
```

Use `isolation: "worktree"` for each agent.

**Dependency handling:**
- If an issue depends on another issue from a previous wave, its agent branches from `feature/<dep-number>` instead of `main`
- If a dependency's agent failed, mark the dependent issue as **BLOCKED** and skip it

Track results: for each issue, record SUCCESS (with worktree branch), DTO_CHANGED, DEPS_CHANGED, FAILED, or BLOCKED.

## Step 4 — Phase 2: verify what is verifiable locally, then let CI be the gate

**Do not run `make qa` or `make test` here.** Neither can complete on a dev machine (768M cap on the `php` service → Rector OOM; empty `pwa_node_modules` volume per worktree; Playwright needs the PWA built and served on `https://localhost`, which a worktree's changes are not). Running them anyway produces red output that has nothing to do with the code and burns a lot of wall-clock. See the "Local QA" section of CLAUDE.md for the container recipes used below.

For each successfully coded branch, **in dependency order**:

1. `cd` into the agent's worktree.
2. If DEPS_CHANGED: `make install`.
3. If DTO_CHANGED: `make typegen`. In a worktree this fails twice over — the php entrypoint waits on a `database` container that `--no-deps` never starts, and `npm run typegen` hits the empty node_modules volume. Fall back to the two commands below, both run **from the worktree root** so the binary and its arguments share one frame of reference (`npm run typegen` uses `pwa/`-relative paths because npm sets the cwd to `pwa/`; invoking the binary directly does not). Commit the regenerated `schema.d.ts`.

   ```bash
   docker compose run --rm --no-deps --entrypoint php php bin/console api:openapi:export > pwa/openapi.json
   pwa/node_modules/.bin/openapi-typescript pwa/openapi.json -o pwa/src/lib/api/schema.d.ts
   ```
4. Run the **QA legs individually** (Rector, PHPStan, PHP-CS-Fixer, tsc, ESLint, Prettier, `npm run i18n:check` — colon, not hyphen — and markdownlint) per the CLAUDE.md recipes. Read each leg's output directly rather than piping it through `tail`, which hides a `Missing script` error behind the pipe's exit status. Fix by hand, commit, retry — up to 3 attempts.
5. Run **PHPUnit `tests/Unit` + `tests/Integration`** against the main stack's network, with the README mounted at `/README.md` (see CLAUDE.md — without it `AlertDocumentationTest` fails on a missing README and looks like a regression). Up to 3 attempts.
6. If a leg still fails after 3 attempts, mark **FAILED** and move on.

**Playwright and the Functional suite are CI's job.** State that plainly in the PR body and in the final report — never imply local E2E coverage you did not have.

**When you add or change a test, prove it can fail.** Mutate the code it guards (remove the guard, revert the line) and check the assertion goes red, then restore. A test committed without that check may assert nothing: in Sprint 44 this caught a REST-boundary test that passed for the wrong reason, and it is the only cheap defence when the suite itself cannot run end-to-end locally.

## Step 5 — Phase 3: Push and create PRs

For each branch that passed Phase 2, create a PR:

1. `git push -u origin feature/<issue-number>`
2. Create the PR with `gh pr create`:
   - Title: Conventional Commit format matching the issue type
   - Body: summary + Auto-critique section per CLAUDE.md
   - Base branch: `main` (or `feature/<dep-number>` for dependent issues)

**Prefer GitHub's native stacks over a hand-rolled `feature/<dep-number>` base.** GitHub ships an official CLI extension:

```bash
gh extension install github/gh-stack   # once per machine
gh stack init <bottom-branch>          # trunk defaults to the repo default branch
gh stack add <next-branch>             # each new layer sits on the one below
gh stack submit                        # opens one PR per branch, bases wired, linked as a Stack
gh stack sync                          # fetch → reconcile → rebase cascade → push → sync PR state
```

Two properties that directly remove Sprint 44's manual work:

- **`gh stack rebase` handles a merged parent by itself** — "if a branch's PR has been merged, the rebase automatically switches to `--onto` mode to correctly replay commits on top of the merge target". That is exactly the manual recipe in Phase 4 §5 below, which had to be run by hand after every squash-merge.
- **`gh stack init` enables `git rerere`**, so a conflict resolved once is replayed automatically on the next rebase. Sprint 44 rebased the same handful of files across four merge rounds and re-resolved the same hunks each time.

Stack metadata lives in `.git/gh-stack` (not committed). If the extension is unavailable, fall back to the manual base + the `--onto` recipe in Phase 4 §5 — keep both paths in mind, the manual one is still correct.

## Step 6 — Phase 4: Drive each PR to READY

For each open PR, run the **bounded surveillance loop** until it converges. **Maximum K=3 cycles per PR.** Never merge — the user merges (a PR is only ever brought to READY).

Each cycle:

1. **CI** — `gh pr checks <pr>`. If red: read the failing logs (`gh run view --log-failed`), apply the smallest fix, commit, push.
2. **Review comments** — fetch both:
   - PR-level comments: `gh pr view <pr> --json comments`
   - Inline review comments: `gh api repos/:owner/:repo/pulls/<pr>/comments`

   Address every **actionable** point, commit, push, then reply to / resolve the corresponding threads. List any comment you deliberately did not action, with the reason.
3. **Conflicts** — `gh pr view <pr> --json mergeable,mergeStateStatus`. If `CONFLICTING`: rebase onto the base branch and resolve **conservatively**. If the resolution is ambiguous or risks discarding work, **stop and flag it** — do not force a resolution.
4. **Parent moved (stacked PR)** — if the PR's base is `feature/<n>` and that branch advanced on origin since the last sync (compare local merge-base vs `origin/feature/<n>`), rebase onto it: `git fetch origin feature/<n> && git rebase origin/feature/<n>`, then `git push --force-with-lease`. **Whenever you push new commits to a parent branch (Phase 4 cycle on that PR), immediately re-rebase every child PR onto it** to keep the stack consistent — the GitHub UI does not do this for you and the stale child will hit a phantom conflict at merge time.
5. **Parent merged (squash) — child retargeted to `main`** — when a parent PR is **squash-merged** and its branch deleted, GitHub retargets the child PR onto `main`, but the child branch still carries the parent's pre-squash commits, so a plain `git rebase origin/main` conflicts and `mergeable` shows `CONFLICTING`. Do **not** rebase onto `main` directly. Instead replay only the child's own commits with `--onto`, dropping the now-merged parent commits:
   ```bash
   git fetch origin
   # <last-parent-commit> = the tip of the former parent branch before squash,
   # i.e. the most-recent (top-most) commit in
   # `git log origin/main..feature/<child>` that belongs to the parent.
   # Everything above it in the log is the child's own work to replay.
   git rebase --onto origin/main <last-parent-commit> feature/<child>
   git push --force-with-lease
   ```
   Verify afterwards that `git log origin/main..HEAD` lists **only** the child's commits. For a 3-deep stack (A→B→C), do this bottom-up as each parent merges. **If the stack was created with `gh stack`, run `gh stack rebase` instead** — it detects the merged parent and switches to `--onto` mode on its own.

**Termination (READY)** when all hold: CI green **AND** `mergeable` **AND** not draft **AND** `claude-code-review.yml` has completed (`gh run view --workflow=claude-code-review.yml` shows `completed`) with **no new blocking comment** (Critical/High). Wait for that workflow to finish before evaluating its output — after a push it is triggered asynchronously and may still be `pending`/`in_progress`.

**Loop note:** each push triggers `synchronize` → the review bot re-runs and auto-resolves its own fixed threads. Treat that auto-resolution as progress; stop as soon as a cycle produces no new blocking comment. After K cycles without convergence, mark the PR **NEEDS ATTENTION** with the blocking reason and move on.

## Step 7 — Update TRACKING.md

For each issue, update the TRACKING.md row:
- READY PR: set status to "En cours", add PR link and branch name
- NEEDS ATTENTION: set status to "En cours", add PR link, note the blocker
- FAILED: set status to "Échoué"
- BLOCKED: set status to "Bloqué"

Also add a **"Ordre de merge et conflits attendus"** section built from the Step 2b overlap map: the required merge order (stacked PRs first), each shared file, and which side should win. This is the artifact the user reads while merging, and it is what makes the conflict resolutions reproducible days later — write the arbitration down, not just the file list.

Commit and push the TRACKING.md update **on a dedicated branch** (e.g. `chore/sprint-<n>-tracking`) — never on a worktree branch and never directly to main. From the main repo (not a worktree): `git switch main && git pull --ff-only && git switch -c chore/sprint-<n>-tracking`, edit, commit, push, open PR.

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
