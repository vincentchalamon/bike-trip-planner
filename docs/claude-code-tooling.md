# Claude Code Tooling

Reference of the [Claude Code](https://code.claude.com/docs) configuration versioned in this
repository, and of the MCP servers it expects you to provide yourself.

## Versioned files

| Path                         | Role                                                                  |
|------------------------------|-----------------------------------------------------------------------|
| `CLAUDE.md`                  | Project instructions loaded into every session                        |
| `.claude/settings.json`      | Project hooks, permissions and MCP server enablement                  |
| `.claude/local-qa.md`        | Recipes for running QA and tests on a dev machine, loaded on demand by `/check` and `/sprint` |
| `.claude/skills/<name>/SKILL.md` | The four project skills below                                     |
| `.claudeignore`              | Paths kept out of Claude's context (dependencies, lock files, binaries, secrets) |
| `.github/workflows/claude.yml`, `claude-code-review.yml` | Claude on GitHub (see below)              |

There is no project-level `.mcp.json`: MCP servers are configured per user (see
[MCP servers](#mcp-servers)).

## Settings (`.claude/settings.json`)

| Key                     | Value                                                                    |
|-------------------------|--------------------------------------------------------------------------|
| `includeCoAuthoredBy`   | `false`: no AI attribution in commits                                    |
| `enableAllProjectMcpServers`, `enabledMcpjsonServers` | enable an `openapi-spec` MCP server when one is defined |
| `permissions.allow`     | read-only shell commands, `git`, `gh`, `make`, `composer`, `docker compose`, `curl` to localhost, web search |
| `permissions.deny`      | `rm`, and reading `.env`, `api/.env.local*`, `api/config/secrets/*`, `pwa/.env*` |
| `permissions.defaultMode` | `acceptEdits`                                                          |

### Hooks

| Event         | Matcher      | Effect                                                                 |
|---------------|--------------|------------------------------------------------------------------------|
| `PreToolUse`  | `Write\|Edit` | Blocks any edit to a path containing `.env`, `schema.d.ts`, `compose.override`, `vendor/`, `node_modules/`, `.claude/settings.json` or `.claude/settings.local.json` |
| `PostToolUse` | `Write\|Edit` | For a `.php` file, runs `make php-cs-fixer` then `make rector`; for a `.ts`/`.tsx` file, runs `make prettier`. Skipped for files under `.claude/worktrees/` |

The formatting targets do not read a file argument: `make php-cs-fixer` and `make rector` process
the whole `api/` and `provisioner/` trees, and `make prettier` only checks formatting.

## Skills

| Command                              | What it does                                                        |
|--------------------------------------|---------------------------------------------------------------------|
| `/pick <issue-number> [base-branch]` | Implements a GitHub issue end to end: branch, code, tests, pull request, then up to 3 surveillance cycles (CI, review comments) until the PR is READY or NEEDS ATTENTION. Never merges. |
| `/sprint <sprint-number>`            | Implements every issue of a sprint in parallel with one worktree agent per issue, picks the model per issue (Opus by default, Sonnet for mechanical changes), runs the same bounded loop on each PR and records the result in `TRACKING.md` through a dedicated branch |
| `/check [qa\|test\|all]`             | Runs the QA and/or test suites, fixes failures and retries, 3 runs at most, and reports the real output. Default `all` |
| `/close [sprint-number]`             | Closes a sprint: removes local worktrees and branches after confirmation, fast-forwards `main`, runs a retrospective that proposes configuration changes without applying them |

## Claude on GitHub

| Trigger                                  | Workflow                 | Effect                                                   |
|------------------------------------------|--------------------------|----------------------------------------------------------|
| `@claude pick [base-branch]` in an issue comment | `claude.yml`, job `pick` | Same flow as `/pick`, in CI and without Docker: creates `feature/<issue>`, implements, opens the PR, follows CI |
| `@claude <instruction>` in an issue or PR comment, a review, or an issue body/title | `claude.yml`, job `claude` | Follows the free-form instruction |
| Pull request opened, synchronized or marked ready for review | `claude-code-review.yml` | Automated review in Conventional Comments format; the bot resolves its own threads once a fix is pushed |

## MCP servers

None is versioned. These are the servers the project workflow assumes, configured in your user
settings or as Claude Code plugins:

| Server        | Used for                                                          | Source |
|---------------|-------------------------------------------------------------------|--------|
| GitHub        | Issues, pull requests, reviews and CI results (`mcp__github__*`)   | <https://github.com/github/github-mcp-server> |
| Context7      | Up-to-date library documentation (Symfony, API Platform, Next.js, Expo...) | <https://github.com/upstash/context7> |
| Playwright    | Driving a browser against the local stack                         | <https://github.com/microsoft/playwright-mcp> |
| `openapi-spec` | Loading the backend's OpenAPI document as context                | for example <https://docs.apidog.com/apidog-mcp-server>, pointed at `https://localhost/docs.jsonopenapi` |

`openapi-spec` needs the local stack to be running. The name matters: it is the one
`.claude/settings.json` enables.

This backend also exposes its own MCP server at `/mcp`, for end users' AI agents rather than for
development. See [Connect an AI agent](connect-an-ai-agent.md) and [MCP tools](mcp-tools.md).
