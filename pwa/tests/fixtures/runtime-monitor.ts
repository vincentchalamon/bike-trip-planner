import type { Page } from "@playwright/test";

export interface RuntimeMonitorOptions {
  /**
   * Substrings of console-error text or response URLs that are tolerated.
   * Extends the defaults rather than replacing them.
   */
  allowlist?: string[];
}

export interface RuntimeMonitor {
  /** Throw if any non-allowlisted console error or HTTP 5xx was collected. */
  assertClean: () => void;
}

/**
 * Noise we always tolerate in the E2E environment:
 * - Mercure SSE connections are intentionally aborted by the mock layer.
 * - net::ERR_ABORTED fires on any route the test aborts on purpose.
 * - favicon 404s are irrelevant to the app under test.
 * - CARTO / MapLibre tile fetches are mocked or blocked and emit benign noise.
 */
const DEFAULT_ALLOWLIST = [
  "mercure",
  "net::ERR_ABORTED",
  "favicon",
  "carto",
  "basemaps.cartocdn",
  "maplibre",
  "tile",
];

/**
 * Attach console-error and HTTP-5xx listeners to a page. Call before
 * navigation, then `assertClean()` once interactions are done.
 */
export function attachRuntimeMonitor(
  page: Page,
  opts: RuntimeMonitorOptions = {},
): RuntimeMonitor {
  const allowlist = [...DEFAULT_ALLOWLIST, ...(opts.allowlist ?? [])].map((s) =>
    s.toLowerCase(),
  );
  const isAllowed = (text: string): boolean => {
    const lower = text.toLowerCase();
    return allowlist.some((entry) => lower.includes(entry));
  };

  const consoleErrors: string[] = [];
  const serverErrors: string[] = [];

  page.on("console", (msg) => {
    if (msg.type() !== "error") return;
    const text = msg.text();
    if (!isAllowed(text)) consoleErrors.push(text);
  });

  page.on("response", (response) => {
    if (response.status() < 500) return;
    const url = response.url();
    if (!isAllowed(url)) serverErrors.push(`${response.status()} ${url}`);
  });

  return {
    assertClean() {
      if (consoleErrors.length === 0 && serverErrors.length === 0) return;
      const parts: string[] = [];
      if (consoleErrors.length > 0) {
        parts.push(
          `Console errors (${consoleErrors.length}):\n  ${consoleErrors.join("\n  ")}`,
        );
      }
      if (serverErrors.length > 0) {
        parts.push(
          `HTTP 5xx responses (${serverErrors.length}):\n  ${serverErrors.join("\n  ")}`,
        );
      }
      throw new Error(`Runtime monitor detected issues:\n${parts.join("\n")}`);
    },
  };
}
