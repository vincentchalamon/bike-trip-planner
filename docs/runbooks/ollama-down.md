# Ollama Down

> **⚠️ Obsolete (2026-06-19) — superseded by [ADR-042](../adr/adr-042-optional-multi-provider-ai-byo-token.md).** The self-hosted Ollama/LLaMA tier this runbook covers has been removed: there is no `ollama` Compose service, no `OLLAMA_*` env, and no `/api/health` Ollama probe. AI is now an optional, per-user, multi-provider bring-your-own-token model (Anthropic, Gemini, OpenAI) — there is no operator-side inference service to restart. AI availability is decided per-user (is a token configured?) — there is no env-var toggle; provider failures degrade gracefully (reason-aware 503 on chat, skipped async analysis). This file is kept for historical reference only.

Ollama is an **optional LLM tier** running in **explicit degraded mode** (ADR-028, "Decision Update — Degraded Mode Re-instated"). When it is unreachable the app **stays up**: AI passes are skipped (not retried to failure), the events are logged `critical` for alerting, `/api/health` stays HTTP **200** (Ollama is excluded from the readiness `$required` set), and the PWA **disables the AI features with an explicit "unavailable" notice** rather than silently dropping them. Trip computation, rule-based alerts, and `TRIP_READY` are unaffected.

## Symptômes

- PHP/worker logs at **`critical`**: `Ollama unreachable — skipping stage analysis.` / `… skipping trip overview synthesis.` / `Ollama unreachable — chat endpoint returning 503.` (plus the in-ride detector/assistant). One per affected message — Sentry groups them by the fixed template.
- `GET /api/health` returns HTTP **200** with `deps.ollama_chat.status: "down"` (and `deps.ollama_analysis.status`) — surfaced **only when `OLLAMA_ENABLED=1`**; the keys are absent entirely when AI is off by config. Ollama is probed but excluded from `$required`, so it does **not** flip the aggregate HTTP status to 503.
- PWA: the floating AI bubble is disabled (title "Assistant IA indisponible"), the Acte 3 "AI unavailable" notice shows, and the refinement card on `/trips/new` is disabled.
- The in-editor chat endpoint (`POST /trips/{id}/chat`) returns **503** while the tier is down.
- Trips still complete: stages, weather, and rule-based alerts render; only the AI narrative is absent.

## Diagnostic

```bash
docker compose ps ollama
docker compose logs --tail=200 ollama
```

Probe the API directly:

```bash
docker compose exec php curl -sS http://ollama:11434/api/tags | jq '.models[].name'
```

Check what the app sees (the keys appear only when `OLLAMA_ENABLED=1`):

```bash
curl -s localhost/api/health | jq '.deps.ollama_chat, .deps.ollama_analysis'
```

Expected models (beta profile, issue #563 — single 3B for analysis + chat):

- `llama3.2:3b` (analysis passes — stage + overview, `num_ctx: 8192`, 60 s timeout; and dialogue pass, 10 s timeout)

8B (`llama3.1:8b`) is reactivable per pass via `OLLAMA_STAGE_MODEL` / `OLLAMA_OVERVIEW_MODEL`.

Check RAM pressure on the VM (Ollama loads on demand with `OLLAMA_KEEP_ALIVE=5m` — ~0 GB idle, ~2.3 GB during a 3B analysis):

```bash
free -h
docker stats --no-stream ollama
```

## Procédure

The app is **not** down — this is a service-restoration runbook, not an incident-mitigation one.

1. **Restart the container**:

   ```bash
   docker compose restart ollama
   ```

   Wait for the healthcheck (`ollama list`) to go green.

2. **Re-pull the missing model** (if `/api/tags` returned an empty list):

   ```bash
   docker compose exec ollama ollama pull llama3.2:3b
   ```

   Operators on resource-constrained hardware may substitute `llama3.2:1b`. Re-pull `llama3.1:8b` only if it was reactivated via `OLLAMA_STAGE_MODEL` / `OLLAMA_OVERVIEW_MODEL`.

3. **Replay failed Messenger messages**, if any. Most AI passes are skipped gracefully and never reach the failed transport, but a transient error mid-call can:

   ```bash
   docker compose exec php bin/console messenger:failed:show
   docker compose exec php bin/console messenger:failed:retry --force
   ```

4. **If the OS killed Ollama for OOM**, free RAM before restarting (see `redis-out-of-memory.md` for Redis pressure) and consider lowering Valhalla or worker count temporarily.

5. **Intentionally running without AI?** Set `OLLAMA_ENABLED=0` on the API workers **and** `NEXT_PUBLIC_AI_ENABLED=0` on the PWA build — the two must mirror each other. The health probe then omits the Ollama keys and the PWA hides AI features outright (no "unavailable" notice). Flipping the front flag requires a **front rebuild** (`NEXT_PUBLIC_*` is build-time).

## Post-action

- `curl http://ollama:11434/api/tags` lists `llama3.2:3b` (it appears once probed/loaded on demand).
- `/api/health` shows `deps.ollama_chat.status: "ok"` for two consecutive probes.
- The PWA AI bubble is active again and the Acte 3 "AI unavailable" notice is gone.
- Replay successful — `failed` transport empty.
- If a model was re-pulled, note the size and time in the incident issue (network bandwidth cost on Oracle).
- If this is the second occurrence in 7 d, open an issue to investigate persistent memory growth in Ollama.

## References

- ADR-028 — Ollama/LLaMA integration ("Decision Update — Degraded Mode Re-instated", Sprint 35.4)
- ADR-027 — Gate mechanism and two-phase pipeline
- Issue #304 — explicit degraded mode (front gating + API `critical` logging)
