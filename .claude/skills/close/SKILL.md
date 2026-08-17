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

For each branch whose PR is **merged or closed**, in this order:

1. **Tear down the worktree's Docker stack.** Each worktree runs its own `docker compose up` under a project named after the worktree directory basename (e.g. `agent-a3008e9ca9369931a`), spinning up a full stack (caddy/php/pwa/database/redis/mercure/ollama/valhalla/…) with its own containers, **named volumes**, and network. These accumulate and overload the machine if left behind. Remove the project (no compose file needed — `-p` is enough):
   ```bash
   docker compose -p <worktree-basename> down --volumes --remove-orphans
   ```
   The project name is the worktree dir basename as Docker sanitizes it (lowercased, non-alphanumeric stripped); for `agent-<hex>` worktrees it is the basename verbatim. This only touches that worktree's project — never the main stack or other worktrees.

2. **Remove the worktree.** Worktrees created via `EnterWorktree` are locked: `git worktree unlock <path>` then `git worktree remove <path>`, finally `git worktree prune`. If the remove fails with **Permission denied** because a sub-tree holds files written by Docker as root (typically `pwa/test-results/`, `recette-report/`, `.features-gen/`, `.phpunit.cache/`, `node_modules/.cache/`, and — after a docs sprint — a root-owned `.mkdocs/`), do **not** use `sudo` and do **not** `chown`/`rm` on the host (the agent has neither permission). Delete them from inside a throwaway **root** container that bind-mounts the worktrees parent — a root container can remove root-owned files:
   ```bash
   # Safety: assert <worktree-basename> is non-empty and contains no path
   # separator before running — an empty value expands to `rm -rf /wt/` and
   # wipes every worktree in the mount. For agent-<hex> names this is always
   # safe; double-check if the worktree was created with a custom path.
   docker run --rm -v <abs-path>/.claude/worktrees:/wt alpine rm -rf /wt/<worktree-basename>
   git worktree prune
   ```
   If Docker itself is unavailable (e.g. the user is mid-cleanup), flag the path and skip — never `sudo`.

   **Prevention (docs sprints):** the `.mkdocs/` case comes from `mkdocs build --strict` run via `docker run python:3.12-slim`, which writes the artifact as root into the mounted worktree. Avoid it at the source by running mkdocs with `--user "$(id -u):$(id -g)"` (same applies to any Docker-in-worktree step that emits build artifacts), so the worktree removal never hits a root-owned dir in the first place.

3. **Delete the branch.** `git branch -d <branch>` — use **`-d`, not `-D`**. If git refuses (unmerged — common with squash-merged PRs whose tip is not an ancestor of `main`), confirm the PR is merged on GitHub (`gh pr view`), then it is safe; flag it and let the user `-D`, or skip. Never force-delete blindly.

4. **Skeletons.** Also clean the `worktree-agent-<id>` branches that `EnterWorktree` leaves behind: `git branch -d worktree-agent-<id>`.

Worktrees live under the gitignored `.claude/worktrees/`, so these removals are purely local.

## Step 5 — Update main

```bash
git checkout main && git pull --ff-only origin main
```

If `--ff-only` fails (local main diverged), report it and stop — do not reset or force.

If the sprint added JS dependencies (workspaces `pwa`/`mobile`), **re-run `npm install` on the `main` checkout** after the merge: deps added in worktrees are absent from main's stale `node_modules`, so `tsc`/`jest` then fail locally on missing modules. This is **not** a regression (CI's `npm ci` from the merged lockfile is green) — just a stale local tree.

## Step 6 — Retrospective into the config (Phase A only)

Skip this step in Phase B — the retrospective was already done in the prior Phase A run.

The retrospective is a **context-budget-neutral-or-negative** operation. `CLAUDE.md` and `MEMORY.md` are the always-loaded preamble: re-prefixed into **every** session AND **every `/sprint` worktree agent** (each agent reads `CLAUDE.md`). A line added here has a cost multiplied by every future session and agent. Historically this step was append-only — one retro gotcha per sprint — and grew `CLAUDE.md` past 240 lines. Do not continue that. **Prune before you add.**

### 6a — Prune first (mandatory)

- **Archive the closing sprint.** Reduce this sprint's `MEMORY.md` entry to a **one-line** pointer and move it to `memory/ARCHIVE.md` (not loaded in the preamble). The underlying memory file stays on disk.
- **Drop the obsolete.** Remove any gotcha whose workaround has been fixed or whose file/flag no longer exists. **Verify existence before keeping** (grep the repo) — a gotcha naming a vanished symbol is noise.

### 6b — Synthesize learnings

Synthesize what went well / badly, grounded in **actual events** (recurring CI failures, review back-and-forths, blocking hooks, conventions repeatedly missed).

### 6c — Route each learning by lifetime (never default to `CLAUDE.md`)

Apply the Boris filter to any `CLAUDE.md` addition: *would removing this line cause Claude to make a mistake in some arbitrary session?* If not, it does not belong in the always-loaded preamble. Route by scope:

| Learning scope | Destination |
|---|---|
| Contract/architecture/gotcha relevant to *every* session | `CLAUDE.md` (rare) |
| Specific to a workflow (QA, sprint, close, pick) | the matching `SKILL.md` |
| Verbose recipe (docker commands, multi-line blocks) | `.claude/local-qa.md` |
| One-off / sprint-specific incident | a `memory/` file + a **one-line** `MEMORY.md` pointer |

**`CLAUDE.md` budget: ~120 lines.** If an addition would push it over, relocate or delete something first — the net line count must not grow.

**Propose, do not apply.** If the user approves, implement via a **feature branch + PR** — never commit config directly to `main`.

## Step 7 — Surface manually-applicable config (Phase A only)

`CLAUDE.md`, the `.claude/skills/*/SKILL.md`, `.claude/local-qa.md`, `MEMORY.md` and the `memory/` files are **not** protected — the agent edits them directly in the retro PR (or in place for the personal `memory/` dir, which lives outside the repo). Do **not** treat those as manual.

Only `.claude/settings.json` and `.claude/settings.local.json` are protected by a `PreToolUse` hook against `Write`/`Edit` (the pattern also matches the global `~/.claude/settings.json`). Any proposal touching them — a hook, a permission, `enabledPlugins`, model/effort — cannot be applied by the agent and must be applied by the user.

When the retrospective produces such a proposal:
1. Include the exact JSON diff in the retro PR description under a clearly-marked "Manual application required" section.
2. After the retro PR is opened, remind the user explicitly that the hook/permission change requires their hand. Do not let it disappear into the PR body.
3. Note that this reminder will not re-surface in Phase B — Phase B does not re-run the retrospective.

## Step 8 — Final report

Print a concise summary: which phase ran, what was removed, what is left for the user (root-owned dirs, manual settings.json changes), and the URL of the retro PR if one was created.
