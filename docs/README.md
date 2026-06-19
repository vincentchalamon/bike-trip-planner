# Documentation

Documentation for **Bike Trip Planner**, organised by what you need to do. It follows the
[Diátaxis](https://diataxis.fr/) principle — separate guidance for **learning**, **doing**,
**looking things up**, and **understanding** — without forcing the literal folder names.

Looking for the product overview instead? See the README ([English](../README.md) ·
[Français](../README.fr.md)).

## Start here — learning

| Document | For |
|---|---|
| [Getting Started](getting-started.md) | First run: prerequisites, install, and booting the stack locally |

## How-to — getting things done

| Document | For |
|---|---|
| [Contributing](contributing.md) | Dev workflow, QA, testing, and how to regenerate screenshots |
| [Deployment](deployment.md) | Production pipeline, Coolify, monitoring, and rollback |
| [Runbooks](runbooks/) | On-call playbooks: workers, DB, Redis, Mercure, releases |
| [Claude Code Tooling](claude-code-tooling.md) | MCP servers, hooks, and skills for AI-assisted development |

## Reference — looking things up

| Document | For |
|---|---|
| [Features](../FEATURES.md) | Complete feature inventory with delivery status |
| [Alert engine](../README.md#alert-engine) | Canonical alert-rule table (severity, priority, trigger) |
| [External data sources](../README.md#external-data-sources) | OSM, DataTourisme, Wikidata, data.gouv.fr, Open-Meteo |
| [Legal & Licensing](legal-and-licensing.md) | Project licence, data attribution, GDPR posture |

## Explanation — understanding the design

| Document | For |
|---|---|
| [Architecture](architecture.md) | System overview: how the pieces fit together and why |
| [Architecture Decision Records](adr/) | Every major technical choice, with context and alternatives |
| [Optional AI (multi-provider, BYO token)](adr/adr-042-optional-multi-provider-ai-byo-token.md) | The opt-in, per-user AI model — choose a provider (Anthropic, Gemini, OpenAI) and bring your own key |
| [AI / LLaMA pipeline](LLaMA.md) | Historical self-hosted AI pipeline structure (obsolete — superseded by ADR-042) |

---

French translations exist for the learning and how-to docs (`*.fr.md`) and for the product
README. Reference and explanation docs (deployment, runbooks, ADRs, architecture) are
maintained in English. Index in French: [docs/README.fr.md](README.fr.md).
