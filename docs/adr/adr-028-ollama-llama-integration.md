# ADR-028: Ollama/LLaMA Integration Architecture (2-Pass Pipeline, Context Window, Hard Dependency)

- **Status:** Accepted
- **Date:** 2026-05-06
- **Depends on:** ADR-001 (Global Architecture), ADR-012 (Rule-based alert engine), ADR-014 (Alert extensibility), ADR-027 (Gate mechanism and two-phase pipeline)
- **Extends:** ADR-012 (adds an LLM-driven narrative layer on top of the rule-based alert engine)

> **Note on numbering.** This ADR was originally tracked as "ADR-027" in the GitHub issue. ADR-027 was concurrently allocated to the gate mechanism and two-phase pipeline. To preserve numbering uniqueness, the Ollama/LLaMA integration was renumbered to ADR-028. The technical scope is unchanged.

## Context and Problem Statement

The Bike Trip Planner exposes two complementary user-facing capabilities that benefit from natural language understanding and generation:

1. **Conversational brief intake** — Users describe their trip ambition in free text ("a 5-day flat loop in Brittany, family-friendly, with a rest day mid-week"). The backend must extract a structured `TripRequest` payload (region, days, daily distance, terrain preference, soft constraints) without forcing the user through a rigid wizard.
2. **Stage analysis and trip narrative** — After the rule-based alert engine has tagged each stage with deterministic alerts (steep ramp, no water for 35 km, last shop before a remote section), the user benefits from a synthesised narrative that contextualises the alerts, surfaces the "why" of each stage, and produces a global overview of the multi-day journey.

Both use cases require an LLM with strong French comprehension, reliable JSON output, and a context window large enough to hold a fully enriched stage payload (geometry summary, alerts, POIs, weather, accommodation candidates). At the same time:

- **Privacy** — Trip briefs may include sensitive plans (children, medical pacing, lodging budget). Sending them to a third-party API breaks the project's local-first posture (ADR-003) and the privacy stance set by the legal pages introduced in commit `d693ba1d`.
- **Cost** — The application has no monetisation path that supports a per-token budget. A free-tier or self-hosted model is required.
- **Latency** — Phase 2 of the gate pipeline (ADR-027) already chains slow I/O-bound enrichments. Adding a network round-trip to a remote LLM API would compound P95 latency.
- **Determinism of the production deployment** — The application's analysis output must be reproducible on the operator's hardware without a remote dependency that can change pricing, change behaviour, deprecate models, or rate-limit at peak.

A further architectural question concerns **what happens when the LLM is not reachable**. The initial design of this ADR considered a graceful fallback (rule-based alerts only, no narrative). That stance was later overturned (see "Decision update" below).

---

## Decision Drivers

- **Self-hosting, privacy, zero marginal cost** — The LLM must run on the same infrastructure as the backend workers; user payloads must never leave the operator's perimeter.
- **Reliable JSON output** — Both the brief intake and the analysis pass consume LLM output as structured data. Free-form prose with a "JSON somewhere in the response" pattern is not acceptable; the runtime must enforce parsability.
- **Context window** — The 8B analysis pass receives a fully enriched stage payload (geometry summary, alerts, POIs, weather, accommodation candidates). Empirical sizing on the largest tested stage payload requires ~5.5K input tokens; the budget must include headroom for the system prompt and the output. **8 192 tokens is the minimum operating context.**
- **Latency budget** — The dialogue model (brief → JSON) must respond in under 3 seconds on the reference hardware (CPU-only fallback acceptable, GPU preferred). The analysis model may take up to 30 seconds per stage in Phase 2 since it runs asynchronously after the preview is already on screen.
- **Prompt-engineering only (no fine-tuning)** — The maintenance cost of a fine-tuned model (dataset curation, training infrastructure, drift management, redeploy on each base-model upgrade) is incompatible with a single-developer project. Prompts must be versioned in the repository and improvable by review.
- **Deterministic production behaviour** — The user-visible output must not switch silently between "with narrative" and "without narrative" depending on whether a sidecar service is up.

---

## Considered Options

### Option A: OpenAI API (GPT-4o or GPT-4o-mini)

Send the brief and stage payloads to OpenAI via the official API. Use the JSON mode for structured outputs. Rely on the platform's quota-managed throughput.

**Rejected.**

- Sends user trip briefs (potentially containing children's names, medical pacing notes, accommodation budget) to a third party. Incompatible with the local-first privacy stance.
- Per-token cost compounds with the 2-pass design (one call per stage + one global overview). On a 7-day trip this is 8 calls per analysis. No monetisation path.
- Adds an external dependency outside the operator's control: pricing, rate limits, model deprecations, and TOS changes are unilateral.
- Network round-trip to an extra-EU service raises GDPR transfer-mechanism questions that the project does not want to answer.

### Option B: Anthropic Claude API

Same shape as Option A with Claude as the provider. Strong JSON adherence and longer context window.

**Rejected for the same reasons as Option A.** The privacy, cost, and external-dependency concerns are provider-agnostic.

### Option C: Ollama with prompt engineering (chosen)

Run [Ollama](https://ollama.com/) as a sidecar service on the same Docker network as the PHP workers. Use two LLaMA 3.x models pulled at deploy time:

- **LLaMA 3B** for the dialogue pass (brief → structured `TripRequest` JSON). Small, fast, sufficient for short structured-extraction prompts.
- **LLaMA 8B** for the analysis pass (per-stage narrative + global overview). Larger context handling, better French fluency, acceptable per-stage latency in Phase 2.

Use Ollama's native `format: "json"` mode to constrain the decoder to JSON output. Configure `num_ctx: 8192` on the 8B model to fit the enriched stage payload plus headroom. Version all system prompts and few-shot examples in the repository under `api/config/prompts/`. **No fine-tuning** — improvements are made by editing prompts and rerunning the regression suite.

**Chosen.** Self-hosted, privacy-preserving, zero marginal cost per inference, latency dominated by local CPU/GPU rather than network. Prompts are reviewable artefacts. Models can be swapped via the `OLLAMA_MODEL_DIALOGUE` / `OLLAMA_MODEL_ANALYSIS` environment variables without code changes.

### Option D: Fine-tuned LLaMA on a curated trip-narrative dataset

Same Ollama runtime, but with custom-trained weights derived from a hand-labelled corpus of bikepacking trip narratives.

**Rejected.** Fine-tuning introduces a curation pipeline, a training pipeline, drift management on each base-model upgrade, and a redistribution question (the fine-tuned weights become a project artefact that must be hosted somewhere). For the gain measured in early prototyping (marginal over a well-engineered system prompt with 3-5 few-shot examples), the maintenance cost is disproportionate. Vanilla prompt engineering is sufficient for the use case.

---

## Decision Outcome

**Chosen: Option C — Ollama with two LLaMA models, JSON mode, prompt engineering only.**

### Models and roles

| Model | Pass | Role | Input | Output | `num_ctx` |
|-------|------|------|-------|--------|-----------|
| **LLaMA 3.x 3B** | Dialogue | Brief → structured `TripRequest` | Free text (typically < 500 tokens) | JSON conforming to the `TripRequest` schema | 4 096 |
| **LLaMA 3.x 8B** | Analysis | Stage narrative + global overview | Enriched stage payload (geometry summary, alerts, POIs, weather, accommodations) | JSON `{stageNarrative, alertsRanking[], overviewSummary}` | **8 192 minimum** |

### Two-pass analysis pipeline

The analysis runs as two distinct LLM invocations triggered from Phase 2 of the gate pipeline (ADR-027), after all enrichments have completed:

1. **Per-stage pass** — One LLM call per stage. Input: that stage's enriched payload. Output: the stage narrative and a re-ranked, contextualised alert list.
2. **Overview pass** — One LLM call after all per-stage passes have completed. Input: a compressed digest of all stage outputs. Output: the global trip narrative (multi-day arc, highlights, mid-trip checkpoints).

This split keeps each input within the 8K token budget (a multi-day trip would otherwise exceed it on a single call) and lets the per-stage passes run in parallel across Messenger workers.

### JSON-mode contract

Every prompt invokes Ollama with `format: "json"` and a JSON Schema embedded in the system prompt. The PHP-side `OllamaClient` rejects any response that fails schema validation; one retry is allowed before the message is dead-lettered for operator review. The schemas live in `api/config/prompts/schemas/` and are referenced by both the prompt templates and the PHP DTOs to keep them in sync.

### Prompt-engineering discipline

- All system prompts and few-shot examples are versioned in `api/config/prompts/` (one file per pass).
- Each prompt change is reviewed via PR and validated against a regression fixture set (`api/tests/Fixtures/llm/`) before merge.
- No fine-tuning. No LoRA adapters. No model-specific weight surgery. The only knobs are: prompt text, few-shot examples, `temperature`, `num_ctx`, and model selection.

### Infrastructure

- Ollama runs as a dedicated container on the project's Docker network (`OLLAMA_BASE_URI=http://ollama:11434`).
- Models are pulled at deploy time via a one-shot init job; the container's volume persists the model blobs across restarts.
- The PHP `OllamaClient` is a scoped HTTP client (per ADR-001 SSRF policy) bound to the Ollama base URI, with a 60 s timeout for the analysis pass and a 10 s timeout for the dialogue pass.

---

## Decision Update — Hard Dependency (Sprint 29)

The original draft of this ADR specified a **graceful fallback**: if Ollama was unreachable, the application would degrade silently to rule-based alerts only, with no narrative and a partial brief intake (form-based fallback). This stance was reversed during sprint 29 (issue #375 "v2 arbitration: AI always active") for the following reasons:

- **Production determinism** — A silently degraded mode produces user-visible output that depends on whether a sidecar service is up. Two users on the same operator can receive materially different reports for the same input. This breaks the reproducibility expectations set by the local-first architecture.
- **UX simplification** — Maintaining two parallel UX paths (with-narrative / without-narrative) doubles the design surface, the test surface, and the documentation surface. The brief intake especially required a complete fallback wizard whose maintenance was disproportionate.
- **Non-negotiable IA quality** — The trip narrative is a core feature of the v2 product, not a "nice-to-have". Shipping a degraded mode would normalise its absence and dilute the product's value proposition.

**Resolution:**

- **Ollama is a hard runtime dependency.** The backend health check fails if `OLLAMA_BASE_URI` is unreachable; the deployment is considered unhealthy and traffic is not routed.
- **No frontend fallback.** The PWA does not implement a "rule-based-only" mode. If the LLM pass fails for a given trip, the trip surfaces an explicit error with a retry action; the user does not silently lose features.
- **Issues closed for this reason.** #304 (LLM unavailable banner), #307 (rule-based-only toggle in settings), and #308 (form-based brief fallback) were closed as "won't do" because they implemented the fallback path that the v2 arbitration removed.
- **Operational note.** Operators who cannot run Ollama (e.g. resource-constrained self-hosting) must run a smaller model (`llama3.2:1b` for dialogue, `llama3.1:8b-q4_K_M` for analysis) rather than disable the LLM tier.

This update supersedes the "graceful fallback" wording from the original draft. The "Consequences → Negative" section below has been amended accordingly.

---

## Consequences

### Positive

- **Privacy preserved end-to-end.** No user payload leaves the operator's perimeter. Trip briefs, even those containing personal information, are processed locally.
- **Zero per-inference cost.** Adding an extra stage or running a regression suite has no marginal cost beyond local compute time.
- **Reviewable prompts.** All prompt logic lives in versioned text files. Behaviour changes are visible in `git diff`, reviewable in PRs, and testable against fixtures.
- **Deterministic production output.** Because Ollama is a hard dependency, every deployed instance produces output of the same shape; no silent feature degradation.
- **Latency contained to Phase 2.** Per ADR-027, the analysis pass runs after the preview is already on screen, so the 8B model's per-stage cost (~10–30 s) does not impact the time-to-first-render.
- **Composable with the rule-based engine.** The rule-based alerts (ADR-012) remain the deterministic source of truth; the LLM only ranks, narrates, and contextualises them. A regression in LLM output does not silently invent alerts.

### Negative

- **Hard runtime dependency on Ollama.** Operators must run Ollama on the same network as the workers. The backend health check refuses to mark the deployment ready otherwise. This is an explicit trade-off (see "Decision update").
- **Memory footprint.** The 8B model in Q4 quantisation occupies ~5 GB resident; the 3B model adds ~2 GB. Operators below 8 GB RAM must use smaller variants and accept reduced quality.
- **Cold-start latency.** First inference after container boot incurs a model-load delay (5–15 s depending on disk speed). Mitigated by a warm-up call in the worker bootstrap.
- **Prompt drift on model upgrades.** Upgrading the base model (e.g. LLaMA 3.x → 3.y) may shift output format adherence. Each model upgrade must run the regression fixture suite before promotion.
- **JSON-mode is not bulletproof.** Even with `format: "json"`, the model can produce schema-valid JSON whose semantic content is wrong (hallucinated POIs, misranked alerts). The validation layer catches structural drift; semantic regressions are caught by the fixture suite.

### Neutral

- The `OllamaClient` is a new scoped HTTP client; it follows the existing SSRF policy unchanged.
- Phase 1 of the gate pipeline (route preview, pacing, stages) does not depend on Ollama. The dialogue pass for brief intake runs **before** Phase 1 and gates the import; the analysis pass runs in Phase 2.
- The two-pass analysis pipeline integrates with the existing `ComputationTracker` (ADR-027): the per-stage and overview passes register `LLAMA_STAGE_*` and `LLAMA_OVERVIEW` computation names so that the gate can wait on them like any other Phase 2 enrichment.

---

## Sources

- [ADR-001: Global Architecture and Separation of Concerns](adr-001-global-architecture-and-separation-of-concerns.md)
- [ADR-012: Rule-based Nudge and Contextual Alert Engine](adr-012-rule-based-nudge-and-contextual-alert-engine.md)
- [ADR-014: Alert Extensibility](adr-014-alert-extensibility.md)
- [ADR-027: Gate Mechanism and Two-Phase Pipeline](adr-027-gate-mechanism-two-phase-pipeline.md)
- [Ollama documentation — JSON mode](https://github.com/ollama/ollama/blob/main/docs/api.md#generate-a-completion)
- [Ollama documentation — `num_ctx` parameter](https://github.com/ollama/ollama/blob/main/docs/modelfile.md#parameter)
- [Meta LLaMA 3 model card](https://github.com/meta-llama/llama-models)
- Issue #297 — ADR-027 Ollama/LLaMA architecture (this ADR, renumbered to 028)
- Issue #375 — v2 arbitration: AI always active (decision update)
- Issues #304, #307, #308 — closed as "won't do" following the hard-dependency resolution
