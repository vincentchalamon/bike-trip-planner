# Recommended Claude Code Tools for Bike Trip Planner

## Context

Bike Trip Planner is a local-first project (PHP/Symfony 8 backend + Next.js 16 frontend + Gotenberg PDF) with no code implemented yet. This document recommends the most useful Claude Code tools (MCP servers, hooks, skills) for this stack, ranked by priority.

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

### 1.3 GitHub MCP Server ⭐ RECOMMENDED

**Purpose:** Native management of PRs, issues, code reviews, GitHub Actions from Claude Code. Essential once the repo is on GitHub.

- **Source:** <https://github.com/github/github-mcp-server>
- **Installation:**

  ```bash
  claude mcp add --scope project --transport http github https://api.githubcopilot.com/mcp/
  ```

  Then `/mcp` in Claude Code to authenticate via OAuth.
- **Key features:** create/merge PRs, comment on issues, search code, trigger CI workflows

---

### 1.4 Apidog MCP Server ⭐ RECOMMENDED

**Purpose:** Loads the backend's OpenAPI spec as context for Claude. Enables generating type-safe frontend code directly from the spec, validating DTO↔TypeScript consistency, and exploring endpoints.

- **Source:** <https://docs.apidog.com/apidog-mcp-server>
- **Installation:** Add to `.mcp.json` at the project root:

  ```json
  {
    "mcpServers": {
      "openapi-spec": {
        "command": "npx",
        "args": ["-y", "apidog-mcp-server@latest", "--oas=https://localhost/docs.json"]
      }
    }
  }
  ```

- **Relevance to Bike Trip Planner:** The type contract (ADR-002) relies on the OpenAPI spec. Having the spec in the Claude context helps maintain backend↔frontend consistency.

---

### 1.5 Docker MCP / Portainer MCP (OPTIONAL)

**Purpose:** Interact with Docker containers (logs, exec, inspect) using natural language. Useful for debugging the project's 3 containers (php, pwa, gotenberg).

- **Source:** <https://github.com/portainer/portainer-mcp>
- **Alternative:** Docker Desktop MCP — <https://www.docker.com/blog/introducing-docker-hub-mcp-server/>
- **Note:** For this project, `make php-shell` / `make pwa-shell` + Bash commands are often sufficient. Consider this if Docker debugging becomes frequent.

---

## 2. Hooks

Hooks are deterministic shell commands triggered at specific points in the Claude Code lifecycle. Configured in `.claude/settings.json` (project) or `~/.claude/settings.json` (global).

**Documentation:** <https://code.claude.com/docs/en/hooks-guide>
**Examples (20+):** <https://aiorg.dev/blog/claude-code-hooks>
**Anthropic Blog:** <https://claude.com/blog/how-to-configure-hooks>

### 2.1 PostToolUse — Auto-format PHP with PHP-CS-Fixer ⭐ RECOMMENDED

**Purpose:** Automatically formats every PHP file edited by Claude, ensuring PSR-12/Symfony compliance without manual intervention.

```json
{
  "hooks": {
    "PostToolUse": [
      {
        "matcher": "Write|Edit",
        "hooks": [
          {
            "type": "command",
            "command": "bash -c 'FILE=$(jq -r \".tool_input.file_path\" <<< \"$(cat)\"); if [[ \"$FILE\" == *.php ]]; then make php-cs-fixer -- \"${FILE#*/api/}\" --quiet 2>/dev/null; fi; exit 0'"
          }
        ]
      }
    ]
  }
}
```

---

### 2.2 PostToolUse — Auto-format TS/TSX with Prettier ⭐ RECOMMENDED

**Purpose:** Automatically formats every TypeScript/TSX file edited by Claude.

```json
{
  "hooks": {
    "PostToolUse": [
      {
        "matcher": "Write|Edit",
        "hooks": [
          {
            "type": "command",
            "command": "bash -c 'FILE=$(jq -r \".tool_input.file_path\" <<< \"$(cat)\"); if [[ \"$FILE\" == *.ts || \"$FILE\" == *.tsx ]]; then make prettier -- --write \"${FILE#*/pwa/}\" 2>/dev/null; fi; exit 0'"
          }
        ]
      }
    ]
  }
}
```

---

### 2.3 PreToolUse — Protect sensitive files ⭐ RECOMMENDED

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

### 2.4 SessionStart — Context reminder after compaction (OPTIONAL)

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

### 3.1 Skill `/webapp-testing` (official Anthropic) ⭐ RECOMMENDED

**Purpose:** Automates web application testing via Playwright — verifies UI, debugs user flows, generates test scripts.

- **Source:** <https://github.com/anthropics/skills/tree/main/skills/webapp-testing>
- **Installation:**

  ```bash
  # From Claude Code:
  /plugin marketplace add anthropics/skills
  ```

  Or manually copy the SKILL.md content into `.claude/skills/webapp-testing/SKILL.md`.

---

### 3.2 Custom Skill `/qa` ⭐ RECOMMENDED

**Purpose:** Runs the full QA pipeline and interprets results. Avoids retyping Docker commands every time.

Create `.claude/skills/qa/SKILL.md`:

```markdown
---
name: qa
description: Run the full QA pipeline (PHPStan, PHP-CS-Fixer, ESLint, Prettier, TypeScript checks) and report results
---

Run the project's quality assurance pipeline:

1. Execute `make qa` from the project root
2. Parse the output to identify:
   - PHPStan errors (with file paths and line numbers)
   - PHP-CS-Fixer violations
   - ESLint warnings/errors
   - Prettier formatting issues
   - TypeScript compilation errors
3. For each issue found, propose a fix
4. After fixing, re-run `make qa` to verify
```

---

### 3.3 Custom Skill `/typegen` (OPTIONAL)

**Purpose:** Regenerates TypeScript types from the backend's OpenAPI spec and verifies frontend compilation.

Create `.claude/skills/typegen/SKILL.md`:

```markdown
---
name: typegen
description: Regenerate TypeScript types from backend OpenAPI spec and verify frontend compilation
---

When backend DTOs change, run the type generation pipeline:

1. Ensure the PHP backend is running: `docker compose ps php`
2. Generate types: `make typegen`
3. Check for TypeScript errors: `make tsc --noEmit`
4. If errors exist, fix the frontend code to match the new types
5. Report what changed in `pwa/src/lib/api/schema.d.ts`
```

---

### 3.4 Skill `/mcp-builder` (official Anthropic) (OPTIONAL)

**Purpose:** Guides the creation of custom MCP servers if you need to integrate specific tools (e.g., wrapper for Symfony `bin/console` commands).

- **Source:** <https://github.com/anthropics/skills/tree/main/skills/mcp-builder>

---

## 4. Summary by Priority

| Priority | Tool                     | Type              | Status                               |
|----------|--------------------------|-------------------|--------------------------------------|
| ✅        | Playwright MCP           | MCP Server        | Already installed                    |
| ✅        | Context7                 | MCP Server        | Already installed                    |
| ⭐        | GitHub MCP               | MCP Server        | To install                           |
| ⭐        | Auto-format PHP (hook)   | Hook PostToolUse  | To configure                         |
| ⭐        | Auto-format TS (hook)    | Hook PostToolUse  | To configure                         |
| ⭐        | File protection (hook)   | Hook PreToolUse   | To configure                         |
| ⭐        | Skill `/qa`              | Skill custom      | To create                            |
| 💡       | Apidog MCP (OpenAPI)     | MCP Server        | To install when backend exists       |
| 💡       | Post-compaction reminder | Hook SessionStart | To configure                         |
| 💡       | Skill `/typegen`         | Skill custom      | To create when pipeline exists       |
| 💡       | Docker/Portainer MCP     | MCP Server        | If Docker debugging becomes frequent |

---

## 5. References

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
