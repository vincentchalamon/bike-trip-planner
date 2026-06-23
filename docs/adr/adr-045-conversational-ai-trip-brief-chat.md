# ADR-045: Conversational AI Trip-Brief Chat

- **Status:** Proposed
- **Date:** 2026-06-23
- **Depends on:** ADR-042 (Per-User BYO-Token AI), ADR-043 (Synchronous Structural Computation)

## Context and Problem Statement

The "Assistant IA" card on the trip-creation screen (`pwa/src/components/ai-chat-card.tsx`) is a **stub**: the assistant turns are canned local strings, and "Valider et continuer" fires a **single-shot** generation via `POST /trips/ai-generate` with the raw textarea text as a `brief` (`handleAiGeneration`, `use-trip-planner.ts`). Recette #649 asks for the opposite: the rider should be able to **converse** with the AI so it can refine the need and ask clarifying questions, and only launch the itinerary computation once the brief is good enough.

The building blocks already exist (do **not** rebuild them):

- **Per-user LLM resolution** (ADR-042): `UserLlmResolverInterface::forUser()` → `ResolvedLlmClient`, with `LlmClientInterface::chat(model, messages[], systemPrompt, options)` returning **free text** (no native `response_format` / tool-call; JSON is requested in the prompt and parsed leniently).
- **Lenient JSON parsing** of an LLM reply into a typed action (`ChatActionInterpreter`, used by the loaded-trip chat `POST /trips/{id}/chat`).
- **The whole generation pipeline**: `POST /trips/ai-generate` → `GenerateAiRouteHandler` → `AiTripGenerationService::generate(brief, ResolvedLlmClient, locale)` (LLM spec extraction → geocode → coverage guard → Valhalla routing), already hardened with corrective re-prompts and `AiGeneratedRoute` outcomes.
- **Per-user rate limiting** on the AI endpoints.

The gap is purely the **multi-turn conversation that builds the brief** before generation. The loaded-trip chat (`POST /trips/{id}/chat`, `TripChatProcessor`, `ChatHistoryStore`) cannot be reused as-is: it is keyed by an existing `tripId`, its actions mutate an existing trip, and its history store assumes a trip exists.

## Decision Drivers

- **Real refinement loop** — the AI must ask questions and converge, not echo canned text (recette #649).
- **Reuse the generation pipeline** — no second LLM→geocode→Valhalla path; the chat only produces the brief.
- **Stateless backend** — the app keeps computation stateless (ADR-043); avoid a new server-side conversation session if the client can carry it.
- **User stays in control** — the rider can launch at any time; the AI recommends, it does not gate.
- **Bounded LLM cost** — one call per user turn (BYO-token), capped by a turn limit and per-user rate limiting.

## Considered Options

### Option A — Keep the single-shot stub

Rejected. No conversation: the rider cannot refine the need and the AI cannot ask questions, which is exactly the recette ask.

### Option B — Server-side conversation session (Redis)

A new pre-trip session store (mirroring `ChatHistoryStore` but keyed by a session id instead of a trip id) holds the running conversation server-side. Rejected: it adds transient state, a session identifier to mint and expire, and a new Redis pool — for no benefit, since the client already holds the message list and the backend is otherwise stateless.

### Option C — Stateless chat endpoint + reuse generation (Chosen)

A new **stateless** endpoint takes the conversation from the client on every turn and returns the assistant reply plus a structured verdict; the client owns the history. Launching the trip reuses the existing `POST /trips/ai-generate` with a consolidated brief.

## Decision

**Option C.**

### New endpoint — `POST /trips/ai-chat` (stateless)

- **Input:** `{ messages: [{ role: "user" | "assistant", content: string }] }` — the full conversation so far, sent by the client each turn.
- **Output (structured per turn):** `{ reply: string, readyToGenerate: boolean, collected: { start?, end?, durationDays?, profile?, ... } }`.
- **Processor (stateless State Processor):** resolves the per-user provider (`forUser()`, graceful 422 if unconfigured, ADR-042), applies a dedicated per-user rate limit, builds a system prompt that instructs the model to (a) ask focused clarifying questions, (b) keep a running structured summary of what it has understood, and (c) signal `readyToGenerate` once it has the essentials. It calls `LlmClientInterface::chat(...)` and parses the reply **leniently** into the structured shape, reusing the `ChatActionInterpreter` approach (Markdown-fence tolerant, prose-wrapper tolerant, fallback to a plain reply with `readyToGenerate: false` on parse failure). **No server-side conversation state** is stored.

### Launching the computation — reuse `POST /trips/ai-generate`

"Lancer le calcul d'itinéraire" consolidates the conversation into a single `brief` (the `collected` parameters, plus the user turns as fallback) and calls the **existing** `POST /trips/ai-generate`. `AiTripGenerationService` re-extracts the spec and runs the unchanged geocode → coverage → Valhalla pipeline. The chat never routes; it only produces the brief.

### Front-end behaviour (`ai-chat-card.tsx`)

- Each user message posts the conversation to `/trips/ai-chat`; the reply is appended to the visible history.
- The **"Lancer le calcul d'itinéraire" button is clickable at any time**, with one **hard floor**: at least a geocodable departure must be present in `collected` (otherwise the generation has nothing to route). `readyToGenerate` from the model drives the **recommended/highlighted** state of the button — the AI recommends, the rider decides.
- A short **recap of the collected parameters** is shown alongside the chat so the rider sees what the AI understood.
- A **turn cap** (client-side) plus the per-user rate limit bound the LLM cost; reaching the cap nudges toward launching.

## Consequences

### Positive

- **Real conversational refinement** — the AI asks questions and converges; the rider keeps control via an always-available launch.
- **Maximum reuse** — generation (`/trips/ai-generate`), provider resolution (ADR-042), lenient JSON parsing, and rate-limiting patterns are reused; only one stateless endpoint and the card UI are new.
- **Stateless** — no pre-trip session, no new Redis pool, consistent with ADR-043.

### Negative

- **Conversation re-sent each turn** — the client posts the growing message list every turn; bounded by the turn cap, and conversations are short by design.
- **No native structured output** — the verdict is requested via the prompt and parsed leniently; mitigated by reusing the battle-tested `ChatActionInterpreter` tolerance and a safe fallback (`readyToGenerate: false`).
- **One LLM call per user turn** — more provider calls than the single-shot stub; on BYO-token plans this is the rider's quota, bounded by the turn cap and rate limit.

### Neutral

- **Double spec pass** — the chat collects parameters, then `AiTripGenerationService` re-extracts the spec from the consolidated brief. Acceptable: it keeps the chat decoupled from the routing pipeline and avoids a second generation path.
- **Stub replaced** — `ai-chat-card.tsx`'s canned `stubGreeting`/`stubReply` and the direct single-shot `handleAiGeneration` wiring are superseded by the live chat; the i18n keys move from canned copy to real chat strings.

## Sources

- [ADR-042: Per-User BYO-Token AI](adr-042-optional-multi-provider-ai-byo-token.md)
- [ADR-043: Synchronous Structural Computation](adr-043-synchronous-structural-computation-async-enrichments.md)
- Issue #751
