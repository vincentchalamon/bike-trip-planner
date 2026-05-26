# Ollama Down

Ollama is a **hard runtime dependency** per ADR-028 — there is no graceful fallback. If the LLM tier is unreachable, the gate pipeline (brief intake, narrative analysis) fails and the deployment is considered unhealthy.

## Symptômes

- Trip creation blocks on the brief intake step (PWA wizard hangs on "Analyzing your brief…")
- PHP logs: `OllamaClient`: timeout, `ConnectException`, or schema validation rejection followed by dead-letter
- `/api/health` reports `ollama: 503` (when present in the health probe)
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

Expected models per ADR-028:

- `llama3.1:8b` (analysis pass, `num_ctx: 8192`, 60 s timeout)
- `llama3.2:3b` (dialogue pass, 10 s timeout)

Check RAM pressure on the VM (Ollama is the largest single consumer, ~6-8 GB):

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

2. **Re-pull missing models** (if `/api/tags` returned an empty or partial list):

   ```bash
   docker compose exec ollama ollama pull llama3.1:8b
   docker compose exec ollama ollama pull llama3.2:3b
   ```

   Operators on resource-constrained hardware may substitute `llama3.2:1b` / `llama3.1:8b-q4_K_M` per ADR-028 — never disable the LLM tier.

3. **Replay failed Messenger messages** once Ollama is healthy:

   ```bash
   docker compose exec php bin/console messenger:failed:show
   docker compose exec php bin/console messenger:failed:retry --force
   ```

4. **If the OS killed Ollama for OOM**, free RAM before restarting (see `redis-out-of-memory.md` for Redis pressure) and consider lowering Valhalla or worker count temporarily.

5. **As a deployment-level mitigation**, Coolify can be configured to gate routing on the `/api/health` probe so users see the maintenance page rather than a half-broken flow.

## Post-action

- `curl http://ollama:11434/api/tags` lists both expected models.
- `/api/health` green for two consecutive probes.
- Replay successful — `failed` transport empty.
- If a model was re-pulled, note the size and time in the incident issue (network bandwidth cost on Oracle).
- If this is the second occurrence in 7 d, open an issue to investigate persistent memory growth in Ollama.

## References

- ADR-028 — Ollama/LLaMA integration (hard dependency, no graceful fallback)
- ADR-027 — Gate mechanism and two-phase pipeline
