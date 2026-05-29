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

## Step 2 — Detect the cleanup phase

Sprint cleanup happens in two waves and `/close` may be invoked at either point:

- **Phase A — sprint cleanup**: feature branches and their worktrees still exist. The first `/close` of the sprint.
- **Phase B — post-retro cleanup**: feature branches are gone, but a `chore/sprint-<n>-*` branch (the retrospective PR from a previous `/close` run) is still around because its PR merged after the first cleanup. A second `/close` to tidy that up.

Detection:
- If `git branch --list 'feature/*'` returns any branch belonging to this sprint → **Phase A**.
- Else if `git branch --list 'chore/sprint-<n>-*'` returns any branch whose PR is merged → **Phase B**.
- Else: nothing to do. Report "Sprint already fully closed" and exit.

Report the detected phase to the user before proceeding.

## Step 3 — Show the cleanup plan (destructive guard)

List the **concrete** items that would be removed, with their PR/merge status:
- **Phase A**: each worktree under `.claude/worktrees/` tied to a sprint issue (`git worktree list`), plus each local `feature/<n>` branch (`gh pr view feature/<n> --json state,mergedAt`).
- **Phase B**: each local `chore/sprint-<n>-*` branch (`gh pr view <branch> --json state,mergedAt`).

**Require explicit user confirmation before any deletion**, regardless of confidence. Never delete a branch/worktree whose PR is still open.

## Step 4 — Clean up (plain git, only after confirmation)

For each branch whose PR is **merged or closed**:
- Worktrees created via `EnterWorktree` are locked. Run `git worktree unlock <path>` then `git worktree remove <path>` and finally `git worktree prune`. If the remove still fails because a sub-tree contains files written by Docker as root (typically `.phpunit.cache/`, `node_modules/.cache/`), **flag the paths to the user and stop** — do not try `sudo`, do not force. The user will clear them by hand.
- `git branch -d <branch>` — use **`-d`, not `-D`**. If git refuses (unmerged), **flag it and skip** rather than force-delete.
- Also clean the skeleton branches `worktree-agent-<id>` that `EnterWorktree` leaves behind: `git branch -d worktree-agent-<id>`.

Worktrees live under the gitignored `.claude/worktrees/`, so these removals are purely local.

## Step 5 — Update main

```bash
git checkout main && git pull --ff-only origin main
```

If `--ff-only` fails (local main diverged), report it and stop — do not reset or force.

## Step 6 — Retrospective into the config (Phase A only)

Skip this step in Phase B — the retrospective was already done in the prior Phase A run.

Synthesize what went well / badly during this sprint, grounded in **actual events** (recurring CI failures, review back-and-forths, blocking hooks, conventions repeatedly missed). For each recurring pain point, propose a **concrete** config change:
- `CLAUDE.md` rule or gotcha
- a new/updated skill (`pick`, `sprint`, `check`, …)
- a hook or permission in `.claude/settings.json`

**Propose, do not apply.** If the user approves a change, implement it via a **feature branch + PR** — never commit config directly to `main`.

## Step 7 — Surface manually-applicable config (Phase A only)

`.claude/settings.json` and `.claude/settings.local.json` are protected by a `PreToolUse` hook against `Write`/`Edit`. Any retrospective proposal that touches them cannot be applied by the agent — it must be applied by the user.

When the retrospective produces such a proposal:
1. Include the exact JSON diff in the retro PR description under a clearly-marked "Manual application required" section.
2. After the retro PR is opened, remind the user explicitly that the hook/permission change requires their hand. Do not let it disappear into the PR body.
3. Note that this reminder will not re-surface in Phase B — Phase B does not re-run the retrospective.

## Step 8 — Final report

Print a concise summary: which phase ran, what was removed, what is left for the user (root-owned dirs, manual settings.json changes), and the URL of the retro PR if one was created.
