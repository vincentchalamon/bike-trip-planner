---
name: pick
description: Implement a GitHub issue end-to-end (branch, code, test, PR, CI, review)
argument-hint: <issue-number> [base-branch]
allowed-tools: Bash(git *), Bash(gh *), Bash(make *), Read, Edit, Write, Glob, Grep
---

Implement GitHub issue `$ARGUMENTS` end-to-end. Parse the arguments: first token is the issue number, second token (optional) is the base branch (default `main`).

If no issue number is provided, ask the user for it before proceeding.

Execute these steps in order:

## Step 1 -- Validate input

Check that the issue number is a valid number. If not, ask the user for a valid issue number.

## Step 2 -- Fetch and confirm

Run `gh issue view <issue-number>` and display the issue title and body summary. Ask the user to confirm before proceeding. Also ask if they want to use a different base branch than the default.

## Step 3 -- Create worktree

```bash
git fetch origin && git worktree add -b feature/<issue-number> .claude/worktrees/feature-<issue-number> origin/<base-branch>
```

> **All subsequent steps MUST be executed from the worktree directory (`.claude/worktrees/feature-<issue-number>`).**

## Step 4 -- Read the issue

Understand all requirements from the issue before writing any code.

## Step 5 -- Implement

Implement the solution respecting CLAUDE.md rules (architecture, SOLID, patterns, tests). If backend DTOs change, run `make typegen`.

## Step 6 -- Run tests

Run `make test`. Fix any failures until the suite passes.

## Step 7 -- Self-review

From the worktree directory, run `git diff <base-branch>...HEAD`. Look for:
- Leftover `console.log`, `dump()`, `dd()`, or debug statements
- Stale TODO/FIXME comments
- Architectural violations

Fix anything found, commit, and push.

## Step 8 -- Create PR (Ready for review)

- From the worktree directory, stage and commit changes following Conventional Commits
- Push the branch: `git push -u origin feature/<issue-number>`
- Create the PR: `gh pr create --fill --base <base-branch>`
- The PR body must include an **Auto-critique** section per CLAUDE.md

## Step 9 -- Drive the PR to READY

Run the **bounded surveillance loop** (max 3 cycles) until the PR is READY. Never merge — the user merges.

Each cycle:
1. **CI** — `gh pr checks`. If red: read the logs (`gh run view --log-failed`), fix, push.
2. **Review comments** — fetch PR-level (`gh pr view --json comments`) and inline (`gh api repos/:owner/:repo/pulls/<pr>/comments`) comments. Address actionable points, push, resolve threads; list any left unactioned with the reason.
3. **Conflicts** — `gh pr view --json mergeable,mergeStateStatus`. If `CONFLICTING`: rebase onto the base and resolve **conservatively**; if ambiguous or risky, stop and flag rather than force.

**READY** when: CI green AND mergeable AND not draft AND `claude-code-review.yml` has completed (`gh run view --workflow=claude-code-review.yml` shows `completed`) with no new blocking (Critical/High) comment. Wait for that workflow to finish before evaluating — it runs asynchronously after each push. After 3 cycles without convergence, report **NEEDS ATTENTION** with the blocker.

## Step 10 -- Finalize

- If TRACKING.md exists, update the row for this issue: change status to "En cours" and branch to `feature/<issue-number>`. Commit and push.
- Remove the worktree: `git worktree remove .claude/worktrees/feature-<issue-number>`
- Report the PR URL and its final status (READY / NEEDS ATTENTION + blocker) to the user.
