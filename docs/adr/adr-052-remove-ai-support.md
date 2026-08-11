# ADR-052: Remove AI Support

- **Status:** Accepted
- **Date:** 2026-08-11
- **Withdraws:** ADR-028 (Ollama/LLaMA integration), ADR-030 (symfony/ai adoption), ADR-042 (optional multi-provider AI, BYO token), ADR-045 (conversational AI trip-brief chat), ADR-046 (temporary AI feature flag), and the historical [LLaMA.md](../LLaMA.md) architecture note.
- **Preserves:** ADR-048 (in-ride assistance without AI).

## Context

The product is pivoting to a native mobile app. AI — trip analysis (per-stage and whole-trip summaries), the conversational chat assistant, itinerary generation from a free-text brief, and the bring-your-own-token multi-provider layer (Anthropic, Google Gemini, OpenAI) — is orthogonal to that pivot. It adds a large, cross-cutting surface (an extra worker, a Messenger transport, DB columns, an encrypted-credential store, a whole API and UI area) that would have to be carried through, and complicate, an upcoming monorepo restructuring.

No production environment exists yet: the app is pre-release, so removing AI now costs nothing in migration or data loss and buys a materially smaller codebase to restructure.

## Decision

Remove all AI support:

- **Backend:** the `Llm` and `Generation` namespaces, the `worker-llm` service, the `llm` Messenger transport, the `ai_*` DB columns, and the API surface (route generation, chat, `AiSettings`).
- **Frontend:** the AI settings, summaries, and chat UI.
- **Encryption key rename:** `AI_TOKEN_ENC_KEY` is renamed **`REFRESH_TOKEN_ENC_KEY`** (parameter `app.refresh_token_enc_key`). It no longer protects any AI provider token; its only remaining role is encrypting auth refresh tokens at rest (`RefreshTokenEncryptor`, SEC-003 / ADR-023).
- **Preserved:** in-ride nearby search (ADR-048) is untouched — it was rebuilt without any LLM on top of the PostGIS Tier-1 index and never depended on the AI stack.

## Consequences

- Reintroducing AI later means re-implementing it, not toggling it back on. The removal PR is the reference diff for what a future implementation would need to restore.
- The full AI history is preserved through the withdrawn ADRs (ADR-028, ADR-030, ADR-042, ADR-045, ADR-046) and LLaMA.md, each carrying a "Withdrawn" banner pointing here.
- One encrypted-credential surface is gone; the sole remaining consumer of the encryption key is the refresh-token encryptor, which the rename now reflects.
