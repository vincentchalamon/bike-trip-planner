# Ollama Down

Ollama is a **hard runtime dependency** per ADR-028 — there is no graceful fallback. If the LLM tier is unreachable, the gate pipeline (brief intake, narrative analysis) fails and the deployment is considered unhealthy.

## Symptômes

- Trip creation blocks on the brief intake step (PWA wizard hangs on "Analyzing your brief…")
- PHP logs: `OllamaClient`: timeout, `ConnectException`, or schema validation rejection followed by dead-letter
- `/api/health` returns HTTP **200** with `deps.ollama_chat.status: "down"` (and `deps.ollama_analysis.status` for the analysis endpoint — same service in beta, distinct once `OLLAMA_ANALYSIS_URL` diverges) in the body — Ollama is probed but excluded from the required set (`HealthController::$required`), so it does **not** flip the aggregate HTTP status to 503
- Messenger `failed` transport grows with `AnalyzeStageMessage` / brief-intake messages

## Diagnostic

```bash
docker compose ps ollama
docker compose logs --tail=200 ollama
```

Probe the API directly:

```bash
docker compose exec php curl -sS http://ollama:11434/api/tags | jq '.models[].name'
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

1. **Restart the container**:

   ```bash
   docker compose restart ollama
   ```

   Wait for the healthcheck (`ollama list`) to go green before retrying any failed message.

2. **Re-pull the missing model** (if `/api/tags` returned an empty list):

   ```bash
   docker compose exec ollama ollama pull llama3.2:3b
   ```

   Operators on resource-constrained hardware may substitute `llama3.2:1b` per ADR-028 — never disable the LLM tier. Re-pull `llama3.1:8b` only if it was reactivated via `OLLAMA_STAGE_MODEL` / `OLLAMA_OVERVIEW_MODEL`.

3. **Replay failed Messenger messages** once Ollama is healthy:

   ```bash
   docker compose exec php bin/console messenger:failed:show
   docker compose exec php bin/console messenger:failed:retry --force
   ```

4. **If the OS killed Ollama for OOM**, free RAM before restarting (see `redis-out-of-memory.md` for Redis pressure) and consider lowering Valhalla or worker count temporarily.

5. **As a deployment-level mitigation**, note that `/api/health` stays HTTP 200 on an Ollama-only outage, so Coolify's probe will **not** trip. To gate routing on the LLM tier (maintenance page rather than a half-broken flow), watch `deps.ollama_chat.status` explicitly via Uptime Kuma rather than the aggregate HTTP status.

## Post-action

- `curl http://ollama:11434/api/tags` lists `llama3.2:3b` (it appears once probed/loaded on demand).
- `/api/health` shows `deps.ollama_chat.status: "ok"` for two consecutive probes.
- Replay successful — `failed` transport empty.
- If a model was re-pulled, note the size and time in the incident issue (network bandwidth cost on Oracle).
- If this is the second occurrence in 7 d, open an issue to investigate persistent memory growth in Ollama.

## References

- ADR-028 — Ollama/LLaMA integration (hard dependency, no graceful fallback)
- ADR-027 — Gate mechanism and two-phase pipeline
