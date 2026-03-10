---
name: Pick Issue
emoji: 🎯
---

Implement GitHub issue #<ISSUE_NUMBER> end-to-end.

Execute these steps in order:

## Step 1 — Fetch and understand the issue

Run `gh issue view <ISSUE_NUMBER>` and read the full issue. Understand all requirements before writing any code.

## Step 2 — Implement

Implement the solution respecting CLAUDE.md rules (architecture, SOLID, patterns, tests). If backend DTOs change, run `make typegen`.

## Step 3 — Run tests

Run `make test`. Fix any failures until the suite passes.

## Step 4 — Self-review

Run `git diff` to review all changes. Look for:
- Leftover `console.log`, `dump()`, `dd()`, or debug statements
- Stale TODO/FIXME comments
- Dead code (unused methods, unreachable branches, orphaned imports)
- Architectural violations

Fix anything found and commit.

## Step 5 — Commit

Stage and commit changes following Conventional Commits format:
`<type>(<scope>): <description>`

The commit message should reference the issue: `Closes #<ISSUE_NUMBER>`.
