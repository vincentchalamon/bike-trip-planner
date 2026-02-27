import type { MercureEvent } from "./types";

const MAX_RECONNECT_DELAY = 30_000;

export class MercureClient {
  private eventSource: EventSource | null = null;
  private reconnectDelay = 1_000;
  private closed = false;
  private callback: ((event: MercureEvent) => void) | null = null;
  private testHandler: ((e: Event) => void) | null = null;

  constructor(
    private readonly mercureHubUrl: string,
    private readonly topic: string,
  ) {}

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

    this.eventSource = new EventSource(url.toString());

    this.eventSource.onmessage = (event) => {
      try {
        const parsed = JSON.parse(event.data) as MercureEvent;
        this.callback?.(parsed);
        this.reconnectDelay = 1_000;
      } catch {
        // Ignore malformed messages
      }
    };

    this.eventSource.onerror = () => {
      this.eventSource?.close();
      this.eventSource = null;

      if (!this.closed) {
        setTimeout(() => this.connect(), this.reconnectDelay);
        this.reconnectDelay = Math.min(
          this.reconnectDelay * 2,
          MAX_RECONNECT_DELAY,
        );
      }
    };
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
