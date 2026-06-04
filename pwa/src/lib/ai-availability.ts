/**
 * Minimal shape of the `/api/health` readiness payload this module relies on.
 * The endpoint is a plain controller (not an API Platform resource), so it is
 * absent from the generated OpenAPI schema — hence this local type rather than
 * `components["schemas"][...]`.
 */
interface HealthResponse {
  deps?: {
    ollama_chat?: { status?: string };
  };
}

/**
 * Runtime AI-tier availability probe (#304).
 *
 * Orthogonal to the build-time {@link AI_ENABLED} flag (which decides whether AI
 * features should exist at all): this answers "is the LLM tier reachable right
 * now?" by reading `/api/health` → `deps.ollama_chat.status`. When the backend
 * runs with `OLLAMA_ENABLED=0` it omits the key entirely, which resolves to
 * unavailable (covers a front-enabled / API-disabled misconfiguration).
 *
 * Failure-open: a network/parse error — or a 429 from the per-IP readiness rate
 * limiter — resolves to `true`, so a transient health hiccup never hides a
 * working feature. A genuine outage still surfaces reactively via the 503 the
 * chat endpoint returns.
 */
export async function fetchAiAvailability(): Promise<boolean> {
  try {
    const res = await fetch(
      `${process.env.NEXT_PUBLIC_API_URL ?? ""}/api/health`,
      { headers: { Accept: "application/json" } },
    );

    // The readiness probe is rate-limited per IP; a 429 is not a real outage.
    if (res.status === 429) return true;

    const body = (await res.json()) as HealthResponse;

    return body.deps?.ollama_chat?.status === "ok";
  } catch {
    return true;
  }
}
