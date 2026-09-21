import type { MercureEnvelope, MercureEvent } from "@btp/core/mercure";
import { API_URL } from "@/lib/constants";

const MAX_RECONNECT_DELAY = 30_000;
const MAX_AUTH_RETRIES = 2;

export class MercureClient {
  private eventSource: EventSource | null = null;
  private reconnectDelay = 1_000;
  private closed = false;
  private authRetries = 0;
  private callback: ((event: MercureEnvelope) => void) | null = null;
  private testHandler: ((e: Event) => void) | null = null;

  constructor(
    private readonly mercureHubUrl: string,
    private readonly topic: string,
    private readonly authHeaderFactory?: () => Promise<string | null>,
  ) {}

  onEvent(callback: (event: MercureEnvelope) => void): void {
    this.callback = callback;
    this.connect();
    // Test-only SSE injection hook (E2E). Gated behind an explicit build flag
    // so it is tree-shaken out of the real production bundle; the mocked E2E
    // suite runs against the prod image, so NODE_ENV cannot be the signal here
    // — the flag is set only in dev and the CI mocked build (SEC-012).
    if (process.env.NEXT_PUBLIC_ENABLE_TEST_HOOKS === "true") {
      this.listenForTestEvents();
    }
  }

  close(): void {
    this.closed = true;
    this.eventSource?.close();
    this.eventSource = null;
    if (this.testHandler) {
      window.removeEventListener("__test_mercure_event", this.testHandler);
      this.testHandler = null;
    }
  }

  private connect(): void {
    if (this.closed) return;

    const url = new URL(this.mercureHubUrl);
    // Mercure 1.0: `topic` became `match` (exact) / `match_urlpattern`. The topic
    // is a fully interpolated `/trips/{id}`, so it is an exact match. Any other
    // parameter under the `match` prefix is rejected by the hub with a 400.
    url.searchParams.set("match", this.topic);

    // withCredentials ensures the __Secure-mercure_access_token cookie is sent cross-origin
    this.eventSource = new EventSource(url.toString(), {
      withCredentials: true,
    });

    this.eventSource.onmessage = (event) => {
      try {
        const parsed = JSON.parse(event.data) as MercureEnvelope;
        this.callback?.(parsed);
        this.reconnectDelay = 1_000;
        this.authRetries = 0;
      } catch {
        // Ignore malformed messages
      }
    };

    this.eventSource.onerror = () => {
      const readyState = this.eventSource?.readyState;
      this.eventSource?.close();
      this.eventSource = null;

      if (this.closed) return;

      // EventSource enters CLOSED state (2) on terminal HTTP errors (401, 403, 404, 5xx…).
      // Attempt re-authentication on the first CLOSED error in case the cookie expired.
      // Skip if no authHeaderFactory — the /detail endpoint requires JWT auth.
      if (
        readyState === EventSource.CLOSED &&
        this.authRetries < MAX_AUTH_RETRIES &&
        this.authHeaderFactory
      ) {
        this.authRetries++;
        this.refreshMercureAuth().then(() => {
          if (!this.closed) {
            setTimeout(() => this.connect(), 500);
          }
        });
        return;
      }

      setTimeout(() => this.connect(), this.reconnectDelay);
      this.reconnectDelay = Math.min(
        this.reconnectDelay * 2,
        MAX_RECONNECT_DELAY,
      );
    };
  }

  /**
   * Re-fetches the trip detail endpoint to obtain a fresh subscriber cookie.
   *
   * The backend sets the `__Secure-mercure_access_token` cookie on trip-related responses.
   * By hitting the detail endpoint, we trigger cookie renewal without side effects.
   */
  private async refreshMercureAuth(): Promise<void> {
    // Extract trip ID from topic (e.g. "/trips/{id}" -> "{id}")
    const tripId = this.topic.replace(/^\/trips\//, "");
    if (!tripId) return;

    try {
      const headers: Record<string, string> = { Accept: "application/ld+json" };
      if (this.authHeaderFactory) {
        const bearer = await this.authHeaderFactory();
        if (bearer) headers["Authorization"] = bearer;
      }

      await fetch(`${API_URL}/trips/${encodeURIComponent(tripId)}/detail`, {
        headers,
        credentials: "include",
      });
    } catch {
      // Silently fail — the reconnect loop will retry
    }
  }

  private listenForTestEvents(): void {
    if (typeof window === "undefined") return;

    this.testHandler = (e: Event) => {
      const customEvent = e as CustomEvent<MercureEnvelope>;
      this.callback?.(customEvent.detail);
    };

    window.addEventListener("__test_mercure_event", this.testHandler);
  }
}
