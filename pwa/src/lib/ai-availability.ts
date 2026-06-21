/**
 * AI-tier availability signal (#304, ADR-042).
 *
 * Historically this probed `/api/health` → `deps.ollama_chat.status` to answer
 * "is the self-hosted LLM tier reachable right now?". Since ADR-042 the AI is no
 * longer a server dependency: it is an optional, per-user cloud provider reached
 * with the user's own token (bring-your-own-token), so the backend no longer
 * exposes an `ollama_chat` dep and there is no server-side tier left to probe.
 *
 * Availability is therefore static `true` here; the real "configured" gate comes
 * from the account AI-settings (`GET /users/me/ai-settings`, see `useAiSettings`).
 * A genuine outage of the user's provider still surfaces reactively via the 503
 * the chat endpoint returns, classified by `AiErrorClassifier`.
 */
export async function fetchAiAvailability(): Promise<boolean> {
  return true;
}
