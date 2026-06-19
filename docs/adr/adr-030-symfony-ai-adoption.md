# ADR-030: symfony/ai Adoption — Platform Yes, Tool-Calling No (on llama3.2:3b)

- **Status:** Accepted
- **Date:** 2026-05-22
- **Depends on:** ADR-028 (Ollama/LLaMA Integration Architecture)
- **Supersedes / refines:** none — operational evolution of the LLM transport layer

> **Superseded by [ADR-042](adr-042-optional-multi-provider-ai-byo-token.md) (2026-06-19):** the self-hosted Ollama/LLaMA tier was replaced by an optional, per-user, multi-provider bring-your-own-token model. The Ollama service, OLLAMA_* env and the bundled LLM resource have been removed. The `symfony/ai-platform` transport layer described below still holds, but the `symfony/ai-ollama-platform` bridge is dropped in favour of the Anthropic, Gemini and OpenAI bridges, and the client is built per user with the user's own key.

## Context and Problem Statement

ADR-028 chose a **custom thin wrapper around Ollama's HTTP API** (`App\Llm\OllamaClient`) plus a **JSON envelope** strategy on top of the `format: "json"` decoding mode: the model is asked to emit `{ "action", "params", "response" }` and a tolerant `ChatActionInterpreter` parses the resulting blob, falling back to an `info` action on malformed output.

Since ADR-028 was written, the Symfony team has published [`symfony/ai`](https://ai.symfony.com/) (v0.9.0 at the time of this ADR), a set of decoupled PHP components that abstract LLM platforms (`symfony/ai-platform` + bridges such as `symfony/ai-ollama-platform`), declarative tools and agents (`symfony/ai-agent` with `#[AsTool]` attributes), conversation persistence (`symfony/ai-chat`), and vector stores (`symfony/ai-store`).

The question for this ADR is **which parts of `symfony/ai` we adopt** and which we deliberately leave on the custom path, given:

- We use exclusively a **local Ollama backend** with **two small LLaMA models** (`llama3.2:3b` for dialogue and in-ride narrative; `llama3.1:8b` for the trip overview). These are constrained by CPU inference on the operator's hardware.
- We have a **dual-storage chat history** (Redis hot 5-turn window in `ChatHistoryStore` + PostgreSQL audit log via `TripChatMessage`) that carries domain semantics (action executed, GPS position at turn time, POI suggestions) beyond a flat `MessageBag`.
- `symfony/ai` is **explicitly experimental**: every component README states it is "experimental" and not covered by Symfony's backward-compatibility promise.

The hypothesis to validate was: **does it pay off to migrate the `App\Llm` layer to `symfony/ai`?** Specifically, two distinct opportunities:

1. **Phase 1 — Transport.** Replace the custom `OllamaClient` HTTP wrapper with `symfony/ai-platform` + the Ollama bridge. Same model, same Ollama daemon, but typed `MessageBag` / `Message`, streaming-ready, multi-provider abstraction "for free".
2. **Phase 2 — Tool calling.** Replace the JSON-envelope strategy with `symfony/ai-agent` and native tool calling: declare each action (`split_stage`, `merge_stages`, `add_waypoint`, `change_accommodation`, `adjust_distance`, `change_route`) as a `#[AsTool]` class, let the LLM emit `tool_calls` directly through Ollama's tool-calling API.

`symfony/ai-chat` and `symfony/ai-store` are out of scope for this ADR — see the rejected options.

## Decision Drivers

- **Reliability on small local models.** Phase 1 is mechanical; Phase 2 depends entirely on whether `llama3.2:3b` can reliably emit tool calls with the JSON-Schema-based protocol. ADR-028 deliberately picked a 3B model for CPU latency; this ADR must not silently regress accuracy.
- **Operational risk.** `symfony/ai` is at v0.9.0 with no backward-compatibility guarantee. Adopting it for a critical path requires a clear value/risk story.
- **Cost of the migration.** The current `App\Llm` layer is 6 files / ~600 lines. The transition has to be cheaper than the value it delivers.
- **Future optionality.** Even if we do not need streaming or multi-provider today, the option of getting them without a rewrite is genuinely useful.
- **No regression to the dual-storage chat model.** `TripChatMessage` carries action, position, and POI suggestion semantics that a single `MessageStoreInterface::save(MessageBag)` cannot express.

## Considered Options

### Option A — Wholesale adoption (Platform + Agent + Chat + Store)

Replace `OllamaClient`, `ChatActionInterpreter`, `ChatHistoryStore`, and `TripChatMessageRepository` with the corresponding `symfony/ai` components.

**Rejected.**

- The custom dual-storage pattern (Redis 5-turn hot window + PostgreSQL audit) carries domain-specific data that does not fit `MessageStoreInterface`.
- Phase 2 (tool calling) does not work on `llama3.2:3b` — see the POC results below. Replacing `ChatActionInterpreter` with the Agent would actively regress dialogue accuracy.
- The migration cost would be high and the rollback path narrow if `symfony/ai` ships a breaking change pre-1.0.

### Option B — Phase 1 only (transport)

Install `symfony/ai-platform` + `symfony/ai-ollama-platform` + `symfony/ai-bundle`. Make `OllamaClient` a façade that delegates to `PlatformInterface` while preserving the `LlmClientInterface` contract (`isEnabled`, `generate`, `chat` with the same legacy return shapes). Keep everything else untouched.

**Chosen.** Trade-offs:

- Pros: typed `MessageBag` / `Message` at the boundary, streaming available without a rewrite, broader provider catalogue ready when needed, transport now maintained by the Symfony team rather than us.
- Cons: each `Platform::invoke()` makes an extra `POST /api/show` round-trip via the default `ModelCatalog` (typed capability discovery). On a local Ollama this is single-digit milliseconds, but it doubles the HTTP traffic.
- Risk surface: small. The contract preserved by the façade means every caller (analyze handlers, intent detector, in-ride assistant, chat processor) keeps its existing call shape, and the existing graceful-fallback / `OllamaUnavailableException` flow remains in place.

### Option C — Phase 1 + Phase 2 (Platform + Agent tool calling)

Same as Option B, plus migration of the dialogue action interpretation from the JSON envelope to native tool calling via `#[AsTool]` classes.

**Rejected based on POC measurement** (see "POC results" below). Tool-calling accuracy on `llama3.2:3b` is **less than half** of the JSON-envelope baseline on the same model and the same Ollama daemon. The failure mode is not a flaky edge case — it is reproducible and structural.

### Option D — Status quo (no adoption)

Stay on the custom `OllamaClient` end-to-end. Re-evaluate `symfony/ai` after it reaches a stable release.

**Rejected.** Phase 1 is low-risk and unlocks streaming / multi-provider optionality at a marginal maintenance cost. The composer dependency is a small price for a layer that we no longer have to maintain ourselves.

## POC Results — Tool calling on llama3.2:3b

The findings below come from a throwaway benchmark that ran 20 representative French chat prompts (3 per action + 3 "info"/no-tool prompts) through both pipelines back-to-back, against the **same** Ollama daemon with the **same** model. Pre-warming and `keep_alive: 30m` were applied to both passes so the comparison was not skewed by model load. The benchmark code was deliberately not kept in the repository — this ADR captures the substance.

The six tools exposed to `symfony/ai-agent` mirrored the six actions of the JSON envelope (`split_stage`, `merge_stages`, `add_waypoint`, `change_accommodation`, `adjust_distance`, `change_route`) as `#[AsTool]` classes, with the same parameter shapes (integer stage indices, string accommodation enums, etc.). The toolbox-generated JSON Schema matched what the bridge sends on the wire (verified by `Symfony\AI\Platform\Bridge\Ollama\Contract\OllamaContract::createToolOption()`).

### Headline numbers (single run, 20 prompts, llama3.2:3b)

| Strategy                               | Match rate    | Median latency |
| -------------------------------------- | ------------- | -------------- |
| `symfony/ai-agent` tool calling        | **7 / 20 (35 %)** | ~5 s           |
| Legacy JSON envelope (`ChatActionInterpreter`) | **15 / 20 (75 %)** | ~15 s          |

Tool calling is consistently ~3× faster (less output to generate — just the tool name and arguments, no conversational `response` field). It is also **less than half** as accurate.

### Reproducible failure modes — tool calling

1. **JSON-Schema leakage into argument values (5 / 20 cases).** The model emits the schema *fragment* in the place of the value:

   ```json
   {"name":"split_stage","args":{"stage":{"description":"The 1-based index of the stage to split","type":"integer"}}}
   ```

   Same prompt, run through the JSON envelope, gives the correct `{"action":"split_stage","params":{"stage":2}}`.

2. **Wrong tool selection on conversational prompts (2 / 3 "info" cases).** `Bonjour !` triggers `split_stage(stage=1)`; `Quelle est la différence entre gravel et bikepacking ?` triggers `split_stage(stage=2)`. The JSON envelope correctly returns `info` in all three "info" cases.

3. **String-coerced arguments.** Even when the value is correct, integer fields come back as strings (`{"stage":"3"}` instead of `{"stage":3}`). Recoverable by callers, but indicative of weak type adherence.

4. **Hallucinated arguments on parameterless tools.** `change_route` (zero-argument tool) was called with `{"stages":"{}"}` and `{"_":"[[1]]"}` — the model fabricates content rather than emitting an empty `arguments` object.

5. **Accommodation type drift.** `Étape 1 : je préfère dormir en gîte` produces `{"stage":"null","type":"gète"}` (typo plus invalid stage), versus the JSON envelope's `info` fallback which at least keeps the conversation usable.

### Why the JSON envelope wins on this size of model

- The schema is communicated in **natural language inside the system prompt**, not as a formal JSON Schema attached to the request. Small models tolerate this much better than the structured tool-protocol metadata.
- The envelope forces a **single, coherent output** (`action` + `params` + conversational `response` in one JSON object). Tool calling separates the action from the user-facing reply, which would require a second model turn to generate the French response and recomposes the latency advantage away.
- `ChatActionInterpreter` is a tolerant parser: it strips Markdown fences, smart quotes, leading prose, and whitelists the action vocabulary. This forgiveness is exactly the safety net a small model needs.

These observations are **specific to ~3B-parameter local models**. On a 70B+ model or GPT-4o/Claude-class providers, tool calling would almost certainly win. The decision below is therefore conditional on continuing to run small local models — see "When to revisit" below.

## Decision Outcome

**Chosen: Option B — Phase 1 only.**

1. `App\Llm\OllamaClient` is rewritten as a thin façade over `Symfony\AI\Platform\PlatformInterface` (Ollama bridge wired through the bundle, pointing to the existing scoped `ollama.client` HTTP client). The `LlmClientInterface` contract is preserved; every caller is untouched. Return shapes remain `['response' => string]` for `generate()` and `['message' => ['content' => string]]` for `chat()` so the existing JSON-envelope flow keeps working.
2. The dialogue layer **keeps the JSON envelope** (`SystemPrompt/dialogue.txt` + `ChatActionInterpreter`). No `#[AsTool]` classes in production.
3. **Not adopted (for now):**
   - `symfony/ai-chat` — `ChatHistoryStore` (Redis hot window) and `TripChatMessage` (PostgreSQL audit with action / GPS / POIs) carry domain semantics that `MessageStoreInterface::save(MessageBag)` cannot express.
   - `symfony/ai-store` — no current product need for embeddings / vector search; Overpass + regex-based `PoiIntentDetector` covers the in-ride flow.
   - `symfony/ai-mcp` — no need yet to expose this app as an MCP server.

### Implementation notes

- The Ollama bridge issues a `POST /api/show` per `Platform::invoke()` to discover model capabilities. For our two-model fixed setup this overhead is ~2 ms locally. Acceptable; if it ever shows up in latency budgets, the fix is a static `ModelCatalogInterface` returning hard-coded `Ollama` instances.
- Cold model loads on CPU can exceed the default 30 s `ollama.client` timeout. The existing `OllamaUnavailableException` + graceful-fallback chain already covers the user-visible behaviour; bump the scoped timeout if this becomes a recurring complaint in production.
- The POC harness used to produce the numbers above is not retained in the repository. If the trigger conditions below fire and we need to re-evaluate, rebuild the benchmark from the methodology described in the previous section.

### When to revisit

The "stay on JSON envelope" decision is conditional on **continuing to run small local models**. Rebuild the benchmark and reopen this ADR when any of these hold:

- We upgrade the dialogue model to ≥ 8B parameters (e.g. switching `OLLAMA_DIALOGUE_MODEL` to `llama3.1:8b` or a tool-call-tuned variant such as `hermes-3:8b`).
- Ollama ships a deterministic fix for the schema-leakage failure mode on 3B models.
- A future `symfony/ai-platform` release introduces an Ollama contract that constrains arguments more strictly (e.g. by post-validating tool_call arguments against the schema).
- Product evolution requires multi-step agentic flows (chain of tool calls, RAG over a vector store) that the JSON envelope cannot express in a single shot.

Until one of these triggers fires: the dialogue stays on the envelope, this ADR stays accepted.
