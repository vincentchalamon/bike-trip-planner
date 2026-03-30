import type { MercureEvent } from "./types";
import { API_URL } from "@/lib/constants";

const MAX_RECONNECT_DELAY = 30_000;
const MAX_AUTH_RETRIES = 2;

/**
 * Checks whether the runtime is a Capacitor native shell.
 * In Capacitor, cookies are not automatically sent with EventSource,
 * so the subscriber JWT must be passed as a query parameter instead.
 */
function isCapacitorRuntime(): boolean {
  return (
    typeof window !== "undefined" && window.location.protocol === "capacitor:"
  );
}

export class MercureClient {
  private eventSource: EventSource | null = null;
  private reconnectDelay = 1_000;
  private closed = false;
  private authRetries = 0;
  private callback: ((event: MercureEvent) => void) | null = null;
  private testHandler: ((e: Event) => void) | null = null;
  private mercureToken: string | null = null;

  constructor(
    private readonly mercureHubUrl: string,
    private readonly topic: string,
  ) {}

  /**
   * Sets the Mercure subscriber JWT for Capacitor usage.
   * On the web, the cookie is sent automatically and this is not needed.
   */
  setMercureToken(token: string): void {
    this.mercureToken = token;
  }

  onEvent(callback: (event: MercureEvent) => void): void {
    this.callback = callback;
    this.connect();
    this.listenForTestEvents();
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
    url.searchParams.set("topic", this.topic);

    // Capacitor: pass JWT as query parameter since cookies are not sent
    if (isCapacitorRuntime() && this.mercureToken) {
      url.searchParams.set("authorization", this.mercureToken);
    }

    // withCredentials ensures the mercureAuthorization cookie is sent cross-origin
    this.eventSource = new EventSource(url.toString(), {
      withCredentials: true,
    });

    this.eventSource.onmessage = (event) => {
      try {
        const parsed = JSON.parse(event.data) as MercureEvent;
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
      if (
        readyState === EventSource.CLOSED &&
        this.authRetries < MAX_AUTH_RETRIES
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
   * The backend sets the `mercureAuthorization` cookie on trip-related responses.
   * By hitting the detail endpoint, we trigger cookie renewal without side effects.
   */
  private async refreshMercureAuth(): Promise<void> {
    // Extract trip ID from topic (e.g. "/trips/{id}" -> "{id}")
    const tripId = this.topic.replace(/^\/trips\//, "");
    if (!tripId) return;

    try {
      const res = await fetch(
        `${API_URL}/trips/${encodeURIComponent(tripId)}/detail`,
        {
          headers: { Accept: "application/ld+json" },
          credentials: "include",
        },
      );

      // For Capacitor, extract the token from the X-Mercure-Token response header
      if (isCapacitorRuntime() && res.ok) {
        const token = res.headers.get("X-Mercure-Token");
        if (token) {
          this.mercureToken = token;
        }
      }
    } catch {
      // Silently fail — the reconnect loop will retry
    }
  }

  private listenForTestEvents(): void {
    if (typeof window === "undefined") return;

    this.testHandler = (e: Event) => {
      const customEvent = e as CustomEvent<MercureEvent>;
      this.callback?.(customEvent.detail);
    };

    window.addEventListener("__test_mercure_event", this.testHandler);
  }
}
