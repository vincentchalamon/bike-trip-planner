# Recommended Claude Code Tools for Bike Trip Planner

## Context

Bike Trip Planner is a local-first project (PHP/Symfony 8 backend + Next.js 16 frontend). This document lists the Claude Code tools (MCP servers, hooks, skills) configured for this project and recommends additional ones.

---

## 1. MCP Servers

### 1.1 Playwright MCP Server (ALREADY INSTALLED)

**Purpose:** Automates browser interactions — E2E tests, UI validation, form filling, screenshots. Essential for the project's Playwright tests (ADR-009).

- **Source:** <https://github.com/microsoft/playwright-mcp>
- **Status:** Already enabled in plugins (`playwright@claude-plugins-official`)
- **Usage:** Available directly via `browser_*` tools (snapshot, click, navigate, etc.)

---

### 1.2 Context7 (ALREADY INSTALLED)

**Purpose:** Queries up-to-date documentation for any library (Symfony, Next.js, API Platform, Zustand, etc.) directly within the Claude Code context. Prevents hallucinations about recent APIs.

- **Source:** <https://github.com/upstash/context7>
- **Status:** Already enabled (`context7@claude-plugins-official`)
- **Usage:** `resolve-library-id` then `query-docs` to get up-to-date documentation

---

### 1.3 GitHub MCP Server (ALREADY INSTALLED)

**Purpose:** Native management of PRs, issues, code reviews, GitHub Actions from Claude Code.

- **Source:** <https://github.com/github/github-mcp-server>
- **Status:** Already enabled (authenticated via OAuth)
- **Usage:** Available via `mcp__github__*` tools (create/merge PRs, comment on issues, search code, read CI results)

---

### 1.4 Apidog MCP Server (ALREADY INSTALLED)

**Purpose:** Loads the backend's OpenAPI spec as context for Claude. Enables generating type-safe frontend code directly from the spec, validating DTO↔TypeScript consistency, and exploring endpoints.

- **Source:** <https://docs.apidog.com/apidog-mcp-server>
- **Status:** Configured in `.mcp.json` at the project root (`openapi-spec` server pointing to `https://localhost/docs.json`)
- **Requirement:** The PHP backend must be running (`make start-dev`) for the server to fetch the spec
- **Relevance:** The type contract (ADR-002) relies on the OpenAPI spec. Having the spec in the Claude context helps maintain backend↔frontend consistency.

---

### 1.5 Docker MCP / Portainer MCP (OPTIONAL)

**Purpose:** Interact with Docker containers (logs, exec, inspect) using natural language. Useful for debugging the project's 3 containers (php, pwa).

- **Source:** <https://github.com/portainer/portainer-mcp>
- **Alternative:** Docker Desktop MCP — <https://www.docker.com/blog/introducing-docker-hub-mcp-server/>
- **Note:** For this project, `make php-shell` / `make pwa-shell` + Bash commands are often sufficient. Consider this if Docker debugging becomes frequent.

---

## 2. Hooks

Hooks are deterministic shell commands triggered at specific points in the Claude Code lifecycle. Configured in `.claude/settings.json` (project) or `~/.claude/settings.json` (global).

**Documentation:** <https://code.claude.com/docs/en/hooks-guide>
**Examples (20+):** <https://aiorg.dev/blog/claude-code-hooks>
**Anthropic Blog:** <https://claude.com/blog/how-to-configure-hooks>

### 2.1 PostToolUse — Auto-format/refactor on write ⭐ RECOMMENDED

**Purpose:** A single hook that automatically formats and refactors files edited by Claude: PHP-CS-Fixer + Rector for `.php` files, Prettier for `.ts`/`.tsx` files.

```json
{
  "hooks": {
    "PostToolUse": [
      {
        "matcher": "Write|Edit",
        "hooks": [
          {
            "type": "command",
            "command": "bash -c 'FILE=$(jq -r \".tool_input.file_path\" <<< \"$(cat)\"); if [[ \"$FILE\" == *\"/.claude/worktrees/\"* ]]; then exit 0; fi; if [[ \"$FILE\" == *.php ]]; then make php-cs-fixer -- \"${FILE#*/api/}\" --quiet 2>/dev/null; make rector -- \"${FILE#*/api/}\" --quiet 2>/dev/null; elif [[ \"$FILE\" == *.ts || \"$FILE\" == *.tsx ]]; then make prettier -- --write . \"${FILE#*/pwa/}\" 2>/dev/null; fi; exit 0'"
          }
        ]
      }
    ]
  }
}
```

---

### 2.4 PreToolUse — Protect sensitive files ⭐ RECOMMENDED

**Purpose:** Prevents Claude from modifying `.env`, `.env.local`, `compose.override.yml`, or generated files (`schema.d.ts`).

```json
{
  "hooks": {
    "PreToolUse": [
      {
        "matcher": "Write|Edit",
        "hooks": [
          {
            "type": "command",
            "command": "bash -c 'FILE=$(jq -r \".tool_input.file_path\" <<< \"$(cat)\"); for p in \".env\" \"schema.d.ts\" \"compose.override\" \"vendor/\" \"node_modules/\"; do if [[ \"$FILE\" == *\"$p\"* ]]; then echo \"Protected file: $p\" >&2; exit 2; fi; done; exit 0'"
          }
        ]
      }
    ]
  }
}
```

---

### 2.5 SessionStart — Context reminder after compaction (OPTIONAL)

**Purpose:** When context is compacted (long sessions), reinjects critical project reminders.

```json
{
  "hooks": {
    "SessionStart": [
      {
        "matcher": "compact",
        "hooks": [
          {
            "type": "command",
            "command": "echo 'Bike Trip Planner: local-first, no DB. Types generated from OpenAPI (npm run typegen). Check docs/adr/ before architectural changes. make qa before commit.'"
          }
        ]
      }
    ]
  }
}
```

---

## 3. Skills (Custom Slash Commands)

Skills are `.claude/skills/<name>/SKILL.md` files in the project. They add `/name` commands that can be invoked in Claude Code.

**Documentation:** <https://code.claude.com/docs/en/skills>

### 3.1 Skill `/pick` (ALREADY INSTALLED) ⭐

**Purpose:** Implements a GitHub issue end-to-end: creates a feature branch, codes the solution, runs tests, opens a PR, monitors CI, and reports back.

- **Location:** `.claude/skills/pick/SKILL.md`
- **Usage:** `/pick <issue-number> [base-branch]`
- **Also available from GitHub:** Comment `@claude pick [base-branch]` on an issue (see §6)

---

### 3.2 Skill `/sprint` (ALREADY INSTALLED) ⭐

**Purpose:** Implements all issues from a sprint in parallel using worktree agents, with dependency-aware ordering and CI monitoring.

- **Location:** `.claude/skills/sprint/SKILL.md`
- **Usage:** `/sprint <sprint-number>`

---

## 4. GitHub Workflows (CI Automation)

Two GitHub Actions workflows enable Claude automation directly from GitHub, without requiring a local Claude Code session.

### 4.1 `claude.yml` — Issue & PR assistant

**Triggers:**

| Comment                          | Where        | Job triggered | Description                                               |
|----------------------------------|--------------|---------------|-----------------------------------------------------------|
| `@claude pick [base-branch]`    | Issue        | `pick`        | Full implementation: branch → code → PR → CI monitoring  |
| `@claude <instruction>`         | Issue or PR  | `claude`      | Free-form: follows the instruction from the comment       |

The `pick` job reproduces the `/pick` skill workflow in CI (without Docker). It parses an optional base branch from the comment, creates `feature/<issue-number>`, implements the solution, opens a PR, monitors CI (up to 3 fix cycles), and reports back on the issue.

### 4.2 `claude-code-review.yml` — Automated PR review

Triggers automatically on every PR (open, sync, reopen, ready for review). Performs a multi-step code review in Conventional Comments format, including security, performance, architecture, and test coverage checks.

---

## 5. Summary by Priority

| Priority | Tool                            | Type              | Status            |
|----------|---------------------------------|-------------------|-------------------|
| ✅        | Playwright MCP                  | MCP Server        | Installed         |
| ✅        | Context7                        | MCP Server        | Installed         |
| ✅        | GitHub MCP                      | MCP Server        | Installed         |
| ✅        | Apidog MCP (OpenAPI)            | MCP Server        | Installed         |
| ✅        | Auto-format/refactor (hook)     | Hook PostToolUse  | Configured        |
| ✅        | File protection (hook)          | Hook PreToolUse   | Configured        |
| ✅        | Skill `/pick`                   | Skill custom      | Installed         |
| ✅        | Skill `/sprint`                 | Skill custom      | Installed         |
| ✅        | `@claude pick` workflow         | GitHub Actions    | Configured        |
| ✅        | Automated code review           | GitHub Actions    | Configured        |
| 💡       | Post-compaction reminder        | Hook SessionStart | Optional          |
| 💡       | Docker/Portainer MCP            | MCP Server        | Optional          |

---

## 6. References

- [Official Claude Code Documentation — MCP](https://code.claude.com/docs/en/mcp)
- [Official Claude Code Documentation — Hooks](https://code.claude.com/docs/en/hooks-guide)
- [Official Claude Code Documentation — Skills](https://code.claude.com/docs/en/skills)
- [GitHub MCP Server](https://github.com/github/github-mcp-server)
- [Playwright MCP Server](https://github.com/microsoft/playwright-mcp)
- [Context7](https://github.com/upstash/context7)
- [Apidog MCP Server](https://docs.apidog.com/apidog-mcp-server)
- [Portainer MCP](https://github.com/portainer/portainer-mcp)
- [Anthropic Skills (official)](https://github.com/anthropics/skills)
- [Awesome Claude Skills](https://github.com/travisvn/awesome-claude-skills)
- [Awesome MCP Servers](https://github.com/punkpeye/awesome-mcp-servers)
- [Hook examples (20+)](https://aiorg.dev/blog/claude-code-hooks)
- [Anthropic Blog — How to configure hooks](https://claude.com/blog/how-to-configure-hooks)
