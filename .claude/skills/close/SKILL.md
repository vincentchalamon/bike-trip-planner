---
name: close
description: Close a sprint — clean up local worktrees/branches, update main, and run a retrospective into the Claude config
argument-hint: "[sprint-number]"
allowed-tools: Bash(git *), Bash(gh *), Read, Write, Edit, Grep, Glob
---

Close the current sprint: tidy local state and capture learnings. Parse `$ARGUMENTS`: an optional sprint number (override). If absent, **auto-detect** the current sprint.

## Step 1 — Detect the sprint

In priority order:
1. The sprint number already discussed in this conversation.
2. Otherwise infer it: list local `feature/<n>` branches (`git branch`) and worktrees (`git worktree list`), then cross-reference those issue numbers against `TRACKING.md` to find which sprint they belong to.
3. If still ambiguous (several active sprints, or none): propose the most likely sprint and **ask the user to confirm** before doing anything.

## Step 2 — Show the cleanup plan (destructive guard)

List the **concrete** items that would be removed, with their PR/merge status:
- Each worktree under `.claude/worktrees/` tied to a sprint issue (`git worktree list`).
- Each local `feature/<n>` branch, with whether its PR is merged/closed/open (`gh pr view feature/<n> --json state,mergedAt`).

**Require explicit user confirmation before any deletion**, regardless of confidence. Never delete a branch/worktree whose PR is still open.

## Step 3 — Clean up (plain git, only after confirmation)

For each branch whose PR is **merged or closed**:
- `git worktree remove <path>` then `git worktree prune`. If removal fails (dirty worktree), **flag it and skip** rather than force.
- `git branch -d feature/<n>` — use **`-d`, not `-D`**. If git refuses (unmerged), **flag it and skip** rather than force-delete.

Worktrees live under the gitignored `.claude/worktrees/`, so these removals are purely local.

## Step 4 — Update main

```bash
git checkout main && git pull --ff-only origin main
```

If `--ff-only` fails (local main diverged), report it and stop — do not reset or force.

## Step 5 — Retrospective into the config

Synthesize what went well / badly during this sprint, grounded in **actual events** (recurring CI failures, review back-and-forths, blocking hooks, conventions repeatedly missed). For each recurring pain point, propose a **concrete** config change:
- `CLAUDE.md` rule or gotcha
- a new/updated skill (`pick`, `sprint`, `check`, …)
- a hook or permission in `.claude/settings.json`

**Propose, do not apply.** If the user approves a change, implement it via a **feature branch + PR** — never commit config directly to `main`.
