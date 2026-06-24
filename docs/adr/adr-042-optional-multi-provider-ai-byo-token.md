# ADR-042: Optional, Multi-Provider AI on a Bring-Your-Own Token (Per-User Cloud Models)

- **Status:** Accepted
- **Date:** 2026-06-19
- **Depends on:** ADR-001 (Global Architecture), ADR-012 (Rule-based alert engine), ADR-027 (Gate mechanism and two-phase pipeline), ADR-030 (symfony/ai adoption), ADR-035 (GDPR account erasure)
- **Supersedes / refines:** ADR-028 (Ollama/LLaMA integration), ADR-030 (symfony/ai transport layer), ADR-039 (beta right-sizing — the LLM RAM/CPU budget freed)
- **Superseded (in part) by:** ADR-046 (temporary AI feature flag): only the "always present in the build / no env flag" stance below; the per-user BYO-token model stands.

## Context and Problem Statement

ADR-028 chose a **self-hosted Ollama/LLaMA tier**: two LLaMA models, run on the same infrastructure as the backend workers, reachable over a scoped HTTP client, with privacy and zero marginal cost as the headline drivers. ADR-030 kept that backend but moved the transport behind `symfony/ai-platform` + the Ollama bridge. ADR-039 then right-sized the beta down to a single `llama3.2:3b` loaded on demand, because the **CPU** (not RAM) of the Oracle Free Tier ARM VM is the binding constraint — a single CPU-only inference pins several cores, and two resident models pushed the VM budget over.

Operating that tier through the closed beta surfaced a different problem than the one ADR-028 set out to solve:

- **The self-hosted model is heavy and fragile on the beta VM.** The model blobs occupy disk, a loaded model holds 2.3-7 GB resident depending on size, and CPU-only inference is slow (the 8B analysis pass took up to ~30 s per stage). Keeping the tier healthy (model pulls, `keep_alive` tuning, OOM avoidance, a dedicated serialised `llm` worker) is operational toil for a <10-user beta.
- **Quality is capped by what fits on the VM.** The 3B beta model is the floor of usefulness; the genuinely good models (Claude, Gemini, GPT-4o-class) cannot run on the free tier at all. ADR-039's own migration path conceded this: better models required moving inference off the VM.
- **Privacy was the original justification for self-hosting, but it cuts both ways.** "No payload leaves the operator's perimeter" is only valuable if the operator pays for the compute. For a free, open-source, single-operator project there is no monetisation path to fund a per-token cloud budget, and self-hosting the model to preserve privacy means the operator carries the whole server cost and footprint.

The question for this ADR: **how do we offer good AI without the operator paying for inference and without pinning a heavy model to the beta VM?**

The answer is to make AI **opt-in, per-user, and bring-your-own-token (BYO)**: the user chooses a cloud provider and pastes their own API key; their key powers their AI features and is billed to their account. The operator runs no model and pays nothing per inference. AI is **off by default** — without configuration, every AI feature is visible but disabled.

## Decision Drivers

- **Zero inference cost and zero inference footprint for the operator.** No model on the VM, no per-token bill. This is the constraint that ADR-028's self-hosting and ADR-039's right-sizing were both fighting.
- **Access to good models.** BYO-token clouds unlock Claude / Gemini / GPT-4o-class quality that the free tier cannot host.
- **Opt-in by default.** AI is not core to the product (the rule-based alert engine of ADR-012 is). It must be safe to ship a deployment with no AI at all, and safe for a user to never touch it.
- **Token security.** A user's provider key is a credential. It must be encrypted at rest, never returned by the API, never logged, and wiped on account erasure (ADR-035).
- **Explicit privacy disclosure.** Sending trip data to a third-party cloud is the opposite of ADR-028's local-first posture. It is acceptable *only* because the user opts in with their own key — and that must be disclosed plainly.
- **Provider neutrality.** No lock-in to a single vendor. The client must be swappable per provider without touching callers.
- **Graceful degradation, not hard dependency.** AI is best-effort. A missing key, a bad key, a quota wall, or a provider outage must never break trip computation or the rule-based alerts.

## Decision

Replace the self-hosted Ollama/LLaMA tier with an **optional, per-user, multi-provider, bring-your-own-token cloud model**. The pipeline shape established by ADR-028 (2-pass analysis, gate integration, graceful degradation) and the transport abstraction of ADR-030 (`symfony/ai-platform`) are kept; the model itself moves from a self-hosted Ollama daemon to a user-chosen cloud provider invoked with the user's own key.

### 1. Opt-in, off by default

Without any configuration, **every AI feature is visible but disabled** (greyed, with a "Configure an AI provider" call-to-action). AI is never silently absent and never silently on. A user enables it in **Account -> Settings** by choosing a provider and pasting their own API key.

### 2. Supported providers (v1)

Cloud providers only. No self-hosted Ollama.

| Provider | `symfony/ai` bridge | Default chat model | Default analysis model |
|----------|---------------------|--------------------|------------------------|
| **Anthropic (Claude)** | `symfony/ai-anthropic-platform` | `claude-haiku-4-5-20251001` | `claude-sonnet-4-6` |
| **Google (Gemini)** | `symfony/ai-gemini-platform` | `gemini-2.0-flash` | `gemini-2.0-flash` |
| **OpenAI** | `symfony/ai-open-ai-platform` | `gpt-4o-mini` | `gpt-4o-mini` |

Models are chosen cheap-but-capable per provider. They are **not user-selectable in v1** — the user picks a provider, not a model.

### 3. Encrypted per-user token + account API

The user's API key is stored encrypted at rest with **libsodium `crypto_secretbox`**, under a dedicated `AI_TOKEN_ENC_KEY` (**never** `APP_SECRET`, so the encryption key has its own rotation/exposure lifecycle). The token is:

- **never returned by the API** — reads expose only `tokenConfigured: bool`, never the ciphertext or plaintext;
- **write-only** on the wire — sent only on `PUT`;
- **never logged**;
- **wiped on GDPR account erasure** (ADR-035): erasing the account removes the token along with the rest of the personal data.

Account API:

| Verb | Route | Body | Response |
|------|-------|------|----------|
| `GET` | `/users/me/ai-settings` | — | `{ provider, tokenConfigured }` |
| `PUT` | `/users/me/ai-settings` | `{ provider, token }` | `{ provider, tokenConfigured: true }` |
| `DELETE` | `/users/me/ai-settings` | — | 204 (token wiped) |

### 4. Provider-neutral `PlatformLlmClient` + per-user factory

The `LlmClientInterface` contract (`isEnabled`, `generate`, `chat`) is preserved so every caller — the analyze handlers, the in-ride assistant, the chat processor — is untouched. The implementation is a provider-neutral `PlatformLlmClient` built **per user at runtime** by a factory: given the user's decrypted token and provider, the factory wires the matching `symfony/ai-platform` bridge and returns a client. There is no process-global model: two concurrent requests from two users run against their own providers with their own keys.

Each provider HTTP client is **SSRF-scoped to that provider's host** (per ADR-001 SSRF policy) with a **30 s timeout** — intentionally higher than the project's 10 s default for external clients (CLAUDE.md), because LLM inference (especially the async analysis pass) routinely takes 20-30 s on the provider backend; the lower default would abort legitimate calls.

### 5. Availability is decided per-user, never by an env flag

The AI features are **always present in the build** — they are part of the product, not a deployment option. What is optional is **per-user activation**: a feature is usable only once *that user* has chosen a provider and saved their token. The application therefore does **not** enable or disable AI through an environment variable. Two env-var flags are removed and **not replaced**: the Ollama-specific `OLLAMA_ENABLED` (which answered "is the self-hosted tier reachable?") and the instance-wide `AI_ENABLED` kill-switch on `LlmClientFactory`/`services.php` (which answered "is AI enabled for all users on this server?").

> **Superseded in part (recette #649, [ADR-046](adr-046-temporary-ai-feature-flag.md)):** a single front-end build flag `NEXT_PUBLIC_ENABLE_AI` (default-off) now hides the whole AI surface to put the feature on hold for a release. It does not revive a backend kill-switch (the backend stays per-user gated) and does not change the per-user activation model described here.

Two states, generalising the ADR-028 degraded-mode contract:

- **No user token** — features visible but **disabled** with a "Configure an AI provider" CTA.
- **User token configured** — full AI for that user.

The only AI-related environment variable that remains is `AI_TOKEN_ENC_KEY` — a cryptographic secret required to decrypt stored tokens, not a feature toggle (production MUST set it; a throwaway dev default keeps the container bootable).

### 6. Error taxonomy + degraded modes

A provider call can fail in ways the old single-tenant Ollama daemon never did (per-user quota, rate limits, revoked keys). Failures map to an `AiFailureReason`:

| Reason | Trigger |
|--------|---------|
| `invalid_token` | HTTP 401 / 403 |
| `quota_exceeded` | HTTP 429, persistent (no `Retry-After` or exhausted budget) |
| `rate_limited` | HTTP 429 with `Retry-After` |
| `unavailable` | HTTP 5xx / timeout |

Degraded behaviour:

- **Synchronous chat** degrades to an HTTP **503** with a **reason-aware message** (e.g. "your API key was rejected" vs "the provider is temporarily unavailable"), so the user can act (fix the key, wait, top up).
- **Asynchronous analysis** (per-stage + overview, ADR-027 Phase 2) is **best-effort**: on failure it is **skipped**, not retried to failure; trip computation and rule-based alerts (ADR-012) are unaffected.
- **AI not configured** is **not an error**: the chat surfaces a graceful in-chat `info` hint ("configure a provider to enable the assistant"), not a 503.

### 7. What the user's key powers

A configured key powers, per user:

- per-stage and whole-trip **analysis** (async, ADR-027 Phase 2);
- the **chat assistant** and its **in-ride POI** mode;
- (**planned**) AI route generation from a free-text brief.

### 8. Privacy disclosure (mandatory)

When AI is enabled, **trip data (route, towns, dates) is sent to the user-chosen provider with the user's own key and billed to their account**. This is a third-party transfer and **must be disclosed** at the point of configuration and in the legal pages. The guarantee is: **no trip data leaves to a third party unless the user opts in by configuring a provider.** With AI off (the default), the local-first posture of ADR-028 holds — nothing leaves the perimeter.

### 9. Ollama removal

> **Implementation status (2026-06-19):** this section describes the target state. The code/config removal lands in PR #716 and this documentation in PR #717; until both are merged, `composer.json` may still carry `symfony/ai-ollama-platform` and the Compose files may still reference the `ollama` service.

The self-hosted tier is removed entirely:

- `App\Llm\OllamaClient` and the `OLLAMA_*` environment variables are deleted.
- The bundled `ollama` Compose service is removed: `compose.ollama.yaml` is deleted, and the `OLLAMA_*` env, the shared `bike-trip-planner-llm` network and the `ollama` `depends_on` are dropped from `compose.yaml`/`compose.dev.yaml`/`compose.recette.yaml`.
- The `/health` Ollama probes (`deps.ollama_chat` / `deps.ollama_analysis`) are removed; AI is no longer a server dependency or health signal — per-user token configuration alone decides availability.
- The `symfony/ai-ollama-platform` package is dropped; the three cloud bridges replace it.

## Consequences

### Positive

- **No inference cost or footprint for the operator.** No model on the VM, no per-token bill — the constraint ADR-028/ADR-039 fought is gone. The beta VM's LLM RAM/CPU budget (2.3-7 GB resident, a serialised `llm` worker, model-pull toil) is freed.
- **Better models available.** Users opt into Claude / Gemini / GPT-4o-class quality the free tier could never host.
- **Privacy by default is stronger, not weaker.** With AI off (the default) nothing leaves the perimeter; the third-party transfer happens only on explicit, per-user opt-in with the user's own key and is disclosed.
- **Provider-neutral.** Adding a fourth provider is a bridge + a default-models row, not a rewrite. Callers are untouched (preserved `LlmClientInterface`).
- **Cleaner failure model.** The explicit `AiFailureReason` taxonomy gives the user actionable messages (bad key vs quota vs outage) instead of a generic "AI unavailable".
- **Computation is never blocked.** Async analysis is best-effort; rule-based alerts (ADR-012) remain the deterministic source of truth.

### Negative

- **Trip data goes to a third party when AI is on.** The local-first guarantee only holds with AI off. This is a deliberate, disclosed, opt-in trade-off — but it is a real departure from ADR-028's stance.
- **The user bears the cost and the key management.** AI quality and availability depend on the user's own provider account, budget, and quota — outside the operator's control. A user with no key gets no AI.
- **Per-user secret to protect.** Storing provider keys adds an encrypted-credential surface (`AI_TOKEN_ENC_KEY` management, erasure on GDPR delete) that the keyless Ollama tier did not have.
- **Per-user, per-provider variance.** Output quality and latency now differ by provider and model, so behaviour is less uniform across users than a single self-hosted model was.

### Neutral

- The 2-pass analysis pipeline, the gate integration (ADR-027), and the `symfony/ai-platform` transport abstraction (ADR-030) are unchanged; only the bridge and the per-user client construction differ.
- Default models are pinned per provider and not user-selectable in v1; making them selectable is a later, additive change.

## Alternatives considered

### Keep the self-hosted Ollama tier (status quo, ADR-028 / ADR-039)

**Rejected.** It carries a recurring operator cost and footprint (disk, 2.3-7 GB resident, CPU-only latency, model-pull and OOM toil, a dedicated serialised worker) to host a model that, on the free tier, is capped at 3B-class quality. The privacy benefit only pays off if the operator funds the compute, which a free single-operator project cannot. ADR-039's own migration path already conceded that good models meant moving inference off the VM.

### Single managed provider (operator picks one cloud, e.g. Anthropic only)

**Rejected.** Removes the footprint but introduces vendor lock-in and still leaves the "who pays per token?" question unanswered. If the operator pays, there is no monetisation path (the same wall ADR-028 hit). If the user pays, there is no reason to constrain them to one vendor — the multi-provider BYO design costs little more (one bridge per provider, one factory) and gives users choice.

### Server-paid keys (operator's key, shared across users)

**Rejected.** A single operator-funded key for all users has no cost ceiling, is trivially abused, and reintroduces the monetisation problem the project has no answer for. It also concentrates all users' trip data under one operator-owned third-party account, which is harder to reason about for GDPR than a per-user, user-owned, user-billed key.

## Sources

- [ADR-001: Global Architecture and Separation of Concerns](adr-001-global-architecture-and-separation-of-concerns.md)
- [ADR-012: Rule-based Nudge and Contextual Alert Engine](adr-012-rule-based-nudge-and-contextual-alert-engine.md)
- [ADR-027: Gate Mechanism and Two-Phase Pipeline](adr-027-gate-mechanism-two-phase-pipeline.md)
- [ADR-028: Ollama/LLaMA Integration](adr-028-ollama-llama-integration.md) — superseded by this ADR for the AI transport/backend
- [ADR-030: symfony/ai Adoption](adr-030-symfony-ai-adoption.md) — the transport abstraction kept; the Ollama bridge replaced by cloud bridges
- [ADR-035: GDPR Account Erasure](adr-035-rgpd-account-erasure.md) — the per-user token is wiped on erasure
- [ADR-039: Beta Right-Sizing on the Oracle Free Tier](adr-039-beta-right-sizing-free-tier.md) — the LLM RAM/CPU budget freed by removing the self-hosted tier
- [symfony/ai](https://ai.symfony.com/) — `symfony/ai-platform` and the Anthropic / Gemini / OpenAI bridges
