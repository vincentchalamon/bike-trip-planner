---
name: check
description: Goal-loop quality gate — run QA/tests and fix until green, with real output as proof
argument-hint: "[qa|test|all]"
allowed-tools: Bash(make *), Read, Edit, Grep, Glob
---

Drive the project to a verified-green state. Parse `$ARGUMENTS`: `qa`, `test`, or `all` (default `all`).

This is a **goal-loop**: the completion condition is a passing run with its real output shown — not an assertion of success.

## Step 1 — Pick the target

- `qa` → `make qa`
- `test` → `make test`
- `all` (default) → `make qa`, then `make test`

## Step 2 — Run and inspect

Run the target. If it passes, go to Step 4.

If it fails, read the **real error output**. Identify the failing tool (PHP-CS-Fixer, Rector, PHPStan, ESLint, Prettier, TS, PHPUnit, Playwright) and the precise cause.

## Step 3 — Fix and retry (max 3 attempts)

Apply the **smallest fix that makes the check pass** — lint/format/type errors, broken assertions, missing typegen. **Do not change feature behavior** to force a pass: if a test reveals a real regression, stop and report it instead of editing the test to match.

Re-run the target. Repeat up to **3 attempts total**. After 3 failed attempts, stop and list the remaining errors verbatim — do not loop further.

## Step 4 — Report with proof

Declare green **only** with the real command output pasted as evidence. If `all` was requested, both `make qa` and `make test` must be green.

If you stopped at the attempt cap or on a suspected real regression, say so explicitly and show the outstanding errors.
