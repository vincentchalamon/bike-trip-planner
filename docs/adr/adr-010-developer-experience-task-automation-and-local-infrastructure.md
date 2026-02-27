# ADR-010: Developer Experience (DX), Task Automation, and Local Infrastructure

**Status:** Accepted

**Date:** 2026-02-19

**Decision Makers:** Lead Developer

**Context:** Bike Trip Planner MVP — Local-first bikepacking trip generator

---

## Context and Problem Statement

The Bike Trip Planner architecture relies on a heterogeneous stack:

1. A stateless API Platform backend running on PHP 8.5.
2. A Next.js 16 frontend running on Node.js.
3. A Gotenberg microservice for PDF generation.

Managing a polyglot monorepo introduces significant cognitive overhead and operational friction. A developer (or an AI
coding agent like Claude Code) must remember different commands to execute tests, run static analysis, or install
dependencies across the two environments (e.g., `docker compose exec php vendor/bin/phpstan analyse` vs.
`docker compose exec pwa npm run lint`).

Without a unified interface for task execution and strict pre-commit hooks, the codebase will inevitably suffer from
formatting inconsistencies, failing tests pushed to the main branch, and a degraded Developer Experience (DX). We must
define a standard mechanism to automate and unify interactions with the local Docker infrastructure.

### Architectural Requirements

| Requirement              | Description                                                                                                                    |
|--------------------------|--------------------------------------------------------------------------------------------------------------------------------|
| Unified Entry Point      | A single command-line interface to orchestrate both PHP and JavaScript tasks.                                                  |
| AI Agent Discoverability | The task runner must be self-documenting so AI agents can easily parse available commands without hallucinations.              |
| Pre-commit Enforcement   | Static analysis (PHPStan, ESLint) and formatting (PHP-CS-Fixer, Prettier) must be enforced locally before a commit is allowed. |
| Platform Agnosticism     | The automation tools should work across Linux, macOS, and WSL2 environments natively.                                          |

---

## Decision Drivers

* **Cognitive Load Reduction** — The developer should focus on domain logic (e.g., the Pacing Engine), not remembering
  long Docker shell commands.
* **Consistency** — If the CI/CD pipeline runs a specific linting command, the exact same command must be easily
  executable locally.
* **Tooling Maturity** — Prefer industry-standard tools over custom, fragile bash scripts.

---

## Considered Options

### Option A: NPM Workspaces / Scripts

Using the root `package.json` to orchestrate everything via `npm run ...` commands.

* *Pros:* Native to the frontend.
* *Cons:* Feels unnatural for the PHP backend. Requires installing Node.js on the host machine just to boot the PHP
  Docker containers, breaking the "Docker-only" encapsulation.

### Option B: Custom Bash Scripts (`./bin/setup.sh`, `./bin/test.sh`)

Writing a directory of shell scripts to handle different tasks.

* *Pros:* Highly customizable.
* *Cons:* Often becomes unmaintainable. Difficult to document interactively. Lacks standard argument parsing without
  heavy boilerplate.

### Option C: GNU Make (Makefile) + Husky (Chosen)

Using a `Makefile` at the root of the repository as the universal task runner, combined with `Husky` (installed via the
frontend container) to trigger Git hooks.

* *Pros:* Universally supported (built into UNIX/macOS/WSL). Extremely declarative. Can easily wrap complex Docker
  commands.
* *Cons:* Syntax (like mandatory tabs instead of spaces) can be finicky for beginners, though trivial for experienced
  developers and AI agents.

---

## Decision Outcome

**Chosen: Option C (GNU Make + Husky)**

### Why Other Options Were Rejected

**Option A (NPM)** relies too heavily on Node.js for backend-specific tasks.
**Option B (Bash scripts)** lacks the declarative dependency graph of Make (e.g., `test: lint test-unit test-e2e`
ensures linting happens before testing). GNU Make is the most robust, cross-platform standard for C-level and polyglot
orchestrations.

---

## Implementation Strategy

### 9.1 — The Self-Documenting Makefile

We will place a `Makefile` at the root of the project. It uses an `awk` script to automatically parse comments appended
with `##`, creating a beautiful, self-documenting help menu. This is particularly useful for AI agents to execute
`make help` and immediately understand the project's capabilities.

**File:** `Makefile`

```makefile
.DEFAULT_GOAL := help
.PHONY: help start stop install qa test php-shell pwa-shell

help: ## Show this help message
 @awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n\nTargets:\n"} /^[a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2 }' $(MAKEFILE_LIST)

## --- 🐳 Docker Infrastructure ---
start: ## Start the Docker environment (Detached)
 docker compose up -d

stop: ## Stop the Docker environment
 docker compose stop

## --- 📦 Dependencies ---
install: ## Install both PHP and Node dependencies
 docker compose exec php composer install
 docker compose exec pwa npm install

## --- 🛡️ Quality Assurance & Linting ---
qa-php: ## Run PHPStan and PHP-CS-Fixer
 docker compose exec php vendor/bin/php-cs-fixer fix --allow-risky=yes
 docker compose exec php vendor/bin/phpstan analyse -l 9 src/

qa-pwa: ## Run ESLint, Prettier, and TypeScript checks
 docker compose exec pwa npm run lint
 docker compose exec pwa npx prettier --write .
 docker compose exec pwa npm run test:ts

qa: qa-php qa-pwa ## Run all QA tools across both stacks

## --- 🧪 Testing ---
test-php: ## Run PHPUnit tests
 docker compose exec php vendor/bin/phpunit

test-e2e: ## Run Playwright End-to-End tests
 docker compose exec pwa npx playwright test

test: qa test-php test-e2e ## Run full test suite (Requires QA to pass first)

## --- 💻 Interactive Shells ---
php-shell: ## Open a bash shell inside the PHP container
 docker compose exec php /bin/sh

pwa-shell: ## Open a bash shell inside the Next.js container
 docker compose exec pwa /bin/sh
```

### 9.2 — Pre-Commit Hooks (Husky)

To ensure no developer (or AI) accidentally commits code that violates our strict typing (ADR-002) or formatting rules,
we will implement Git hooks using Husky. Since Husky requires Node.js, it will be initialized in the `pwa` directory but
will execute `make` commands at the repository root.

**File:** `pwa/package.json`

```json
{
  "scripts": {
    "prepare": "husky",
    "lint": "next lint",
    "test:ts": "tsc --noEmit"
  },
  "devDependencies": {
    "husky": "^9.0.0"
  }
}
```

**File:** `pwa/.husky/pre-commit`

```bash
#!/usr/bin/env sh
. "$(dirname -- "$0")/_/husky.sh"

# Navigate back to the monorepo root
cd ..

# Run the unified Quality Assurance make target
echo "🚀 Running Quality Assurance checks (PHP & TS)..."
make qa

# If 'make qa' fails, the commit is aborted.
```

### 9.3 — Developer Workflow Definition

With this infrastructure in place, the official workflow for any contributor (human or AI) is strictly defined:

1. **Bootstrapping:** `make start-dev`.
2. **Development:** Modify code in `api/` or `pwa/`.
3. **Type Generation:** If backend DTOs change, run `npm run typegen` in the `pwa/` folder to update OpenAPI schemas (as
   per ADR-002).
4. **Validation:** Run `make test` to verify logic.
5. **Commit:** The `pre-commit` hook automatically formats code and catches static analysis errors.

---

## Verification

1. **Self-Documentation:** Run `make` or `make help` in the terminal. Verify that the output lists all commands with
   their descriptions formatted in cyan.
2. **Pre-commit Rejection:** Introduce a deliberate TypeScript type error in `pwa/src/store/useTripStore.ts`. Attempt to
   `git commit`. Verify that the Husky hook intercepts the commit, runs `make qa`, and aborts the commit process with a
   non-zero exit code.

---

## Consequences

### Positive

* **AI-Friendly Protocol:** An AI agent like Claude Code can simply execute `make help` upon entering the directory and
  immediately understand how to interact with the project safely.
* **Zero Host Dependencies:** A developer only needs Docker and Make installed on their machine. No local PHP or Node.js
  installations are required to run tests or install packages.
* **Enforced Quality:** The Husky pre-commit hook mathematically guarantees that the main branch will never contain code
  that violates PHPStan Level 9 or TypeScript strict modes.

### Negative

* **Windows Compatibility:** GNU Make is native to macOS and Linux. Windows users must use WSL2 (Windows Subsystem for
  Linux) to utilize this DX setup efficiently. (Given the Docker requirements, WSL2 is already mandatory for a smooth
  Windows experience anyway).

### Neutral

* The Git hooks run inside Docker containers. This means committing code will take slightly longer (3-10 seconds) than
  running local bare-metal linters, which is a worthwhile trade-off for perfectly synchronized environments.
