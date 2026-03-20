# Outils Claude Code recommandes pour Bike Trip Planner

*[English version](claude-code-tooling.md)*

## Contexte

Bike Trip Planner est un projet local-first (backend PHP/Symfony 8 + frontend Next.js 16). Ce document liste les outils Claude Code (serveurs MCP, hooks, skills) configures pour ce projet et en recommande d'autres.

---

## 1. Serveurs MCP

### 1.1 Serveur MCP Playwright (DEJA INSTALLE)

**Objectif :** Automatise les interactions navigateur -- tests E2E, validation UI, remplissage de formulaires, captures d'ecran. Essentiel pour les tests Playwright du projet (ADR-009).

- **Source :** <https://github.com/microsoft/playwright-mcp>
- **Statut :** Deja active dans les plugins (`playwright@claude-plugins-official`)
- **Utilisation :** Disponible directement via les outils `browser_*` (snapshot, click, navigate, etc.)

---

### 1.2 Context7 (DEJA INSTALLE)

**Objectif :** Interroge la documentation a jour de n'importe quelle librairie (Symfony, Next.js, API Platform, Zustand, etc.) directement dans le contexte Claude Code. Empeche les hallucinations sur les API recentes.

- **Source :** <https://github.com/upstash/context7>
- **Statut :** Deja active (`context7@claude-plugins-official`)
- **Utilisation :** `resolve-library-id` puis `query-docs` pour obtenir la documentation a jour

---

### 1.3 Serveur MCP GitHub (DEJA INSTALLE)

**Objectif :** Gestion native des PR, issues, revues de code et GitHub Actions depuis Claude Code.

- **Source :** <https://github.com/github/github-mcp-server>
- **Statut :** Deja active (authentifie via OAuth)
- **Utilisation :** Disponible via les outils `mcp__github__*` (creer/merger des PR, commenter des issues, rechercher du code, lire les resultats CI)

---

### 1.4 Serveur MCP Apidog (DEJA INSTALLE)

**Objectif :** Charge la spec OpenAPI du backend comme contexte pour Claude. Permet de generer du code frontend type-safe directement depuis la spec, valider la coherence DTO/TypeScript, et explorer les endpoints.

- **Source :** <https://docs.apidog.com/apidog-mcp-server>
- **Statut :** Configure dans `.mcp.json` a la racine du projet (serveur `openapi-spec` pointant vers `https://localhost/docs.json`)
- **Prerequis :** Le backend PHP doit tourner (`make start-dev`) pour que le serveur puisse recuperer la spec
- **Pertinence :** Le contrat de types (ADR-002) repose sur la spec OpenAPI. Avoir la spec dans le contexte Claude aide a maintenir la coherence backend/frontend.

---

### 1.5 Docker MCP / Portainer MCP (OPTIONNEL)

**Objectif :** Interagir avec les conteneurs Docker (logs, exec, inspect) en langage naturel. Utile pour debugger les 3 conteneurs du projet (php, pwa).

- **Source :** <https://github.com/portainer/portainer-mcp>
- **Alternative :** Docker Desktop MCP -- <https://www.docker.com/blog/introducing-docker-hub-mcp-server/>
- **Note :** Pour ce projet, `make php-shell` / `make pwa-shell` + commandes Bash sont souvent suffisants. A envisager si le debug Docker devient frequent.

---

## 2. Hooks

Les hooks sont des commandes shell deterministes declenchees a des points specifiques du cycle de vie de Claude Code. Configures dans `.claude/settings.json` (projet) ou `~/.claude/settings.json` (global).

**Documentation :** <https://code.claude.com/docs/en/hooks-guide>
**Exemples (20+) :** <https://aiorg.dev/blog/claude-code-hooks>
**Blog Anthropic :** <https://claude.com/blog/how-to-configure-hooks>

### 2.1 PostToolUse -- Auto-format/refactoring a l'ecriture (RECOMMANDE)

**Objectif :** Un seul hook qui formate et refactorise automatiquement les fichiers edites par Claude : PHP-CS-Fixer + Rector pour les fichiers `.php`, Prettier pour les fichiers `.ts`/`.tsx`.

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

### 2.4 PreToolUse -- Proteger les fichiers sensibles (RECOMMANDE)

**Objectif :** Empeche Claude de modifier `.env`, `.env.local`, `compose.override.yml`, ou les fichiers generes (`schema.d.ts`).

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

### 2.5 SessionStart -- Rappel de contexte apres compaction (OPTIONNEL)

**Objectif :** Lorsque le contexte est compacte (sessions longues), reinjecte des rappels critiques du projet.

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

## 3. Skills (commandes slash personnalisees)

Les skills sont des fichiers `.claude/skills/<nom>/SKILL.md` dans le projet. Ils ajoutent des commandes `/nom` invocables dans Claude Code.

**Documentation :** <https://code.claude.com/docs/en/skills>

### 3.1 Skill `/pick` (DEJA INSTALLE)

**Objectif :** Implemente une issue GitHub de bout en bout : cree une branche de fonctionnalite, code la solution, lance les tests, ouvre une PR, surveille la CI, et rapporte le resultat.

- **Emplacement :** `.claude/skills/pick/SKILL.md`
- **Utilisation :** `/pick <numero-issue> [branche-base]`
- **Aussi disponible depuis GitHub :** Commentez `@claude pick [branche-base]` sur une issue (voir section 6)

---

### 3.2 Skill `/sprint` (DEJA INSTALLE)

**Objectif :** Implemente toutes les issues d'un sprint en parallele en utilisant des agents worktree, avec un ordonnancement tenant compte des dependances et un monitoring CI.

- **Emplacement :** `.claude/skills/sprint/SKILL.md`
- **Utilisation :** `/sprint <numero-sprint>`

---

## 4. Workflows GitHub (automatisation CI)

Deux workflows GitHub Actions permettent l'automatisation Claude directement depuis GitHub, sans necessiter de session Claude Code locale.

### 4.1 `claude.yml` -- Assistant issue & PR

**Declencheurs :**

| Commentaire | Ou | Job declenche | Description |
|-------------|-----|---------------|-------------|
| `@claude pick [branche-base]` | Issue | `pick` | Implementation complete : branche -> code -> PR -> monitoring CI |
| `@claude <instruction>` | Issue ou PR | `claude` | Libre : suit l'instruction du commentaire |

Le job `pick` reproduit le workflow du skill `/pick` en CI (sans Docker). Il parse une branche base optionnelle depuis le commentaire, cree `feature/<numero-issue>`, implemente la solution, ouvre une PR, surveille la CI (jusqu'a 3 cycles de correction), et rapporte sur l'issue.

### 4.2 `claude-code-review.yml` -- Revue de code automatisee des PR

Se declenche automatiquement sur chaque PR (ouverture, synchronisation, reouverture, pret pour revue). Effectue une revue de code multi-etapes au format Conventional Comments, incluant des verifications de securite, performance, architecture et couverture de tests.

---

## 5. Resume par priorite

| Priorite | Outil | Type | Statut |
|----------|-------|------|--------|
| Installe | Playwright MCP | Serveur MCP | Installe |
| Installe | Context7 | Serveur MCP | Installe |
| Installe | GitHub MCP | Serveur MCP | Installe |
| Installe | Apidog MCP (OpenAPI) | Serveur MCP | Installe |
| Installe | Auto-format/refactoring (hook) | Hook PostToolUse | Configure |
| Installe | Protection de fichiers (hook) | Hook PreToolUse | Configure |
| Installe | Skill `/pick` | Skill personnalise | Installe |
| Installe | Skill `/sprint` | Skill personnalise | Installe |
| Installe | Workflow `@claude pick` | GitHub Actions | Configure |
| Installe | Revue de code automatisee | GitHub Actions | Configure |
| Optionnel | Rappel post-compaction | Hook SessionStart | Optionnel |
| Optionnel | Docker/Portainer MCP | Serveur MCP | Optionnel |

---

## 6. References

- [Documentation officielle Claude Code -- MCP](https://code.claude.com/docs/en/mcp)
- [Documentation officielle Claude Code -- Hooks](https://code.claude.com/docs/en/hooks-guide)
- [Documentation officielle Claude Code -- Skills](https://code.claude.com/docs/en/skills)
- [GitHub MCP Server](https://github.com/github/github-mcp-server)
- [Playwright MCP Server](https://github.com/microsoft/playwright-mcp)
- [Context7](https://github.com/upstash/context7)
- [Apidog MCP Server](https://docs.apidog.com/apidog-mcp-server)
- [Portainer MCP](https://github.com/portainer/portainer-mcp)
- [Anthropic Skills (officiel)](https://github.com/anthropics/skills)
- [Awesome Claude Skills](https://github.com/travisvn/awesome-claude-skills)
- [Awesome MCP Servers](https://github.com/punkpeye/awesome-mcp-servers)
- [Exemples de hooks (20+)](https://aiorg.dev/blog/claude-code-hooks)
- [Blog Anthropic -- Comment configurer les hooks](https://claude.com/blog/how-to-configure-hooks)
