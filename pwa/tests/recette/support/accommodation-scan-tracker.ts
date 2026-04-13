import type { Page, Request } from "@playwright/test";

let pendingAccommodationScanRequest: Promise<Request> | undefined;

export function trackAccommodationScanRequest(page: Page): void {
  pendingAccommodationScanRequest = page.waitForRequest(
    (req) =>
      req.url().includes("/accommodations/scan") && req.method() === "POST",
    { timeout: 5000 },
  );
}

export async function takeAccommodationScanRequest(): Promise<Request> {
  if (!pendingAccommodationScanRequest) {
    throw new Error(
      "Accommodation scan request was not tracked before assertion",
    );
  }

  const request = await pendingAccommodationScanRequest;
  pendingAccommodationScanRequest = undefined;
  return request;
}

export function resetAccommodationScanRequest(): void {
  pendingAccommodationScanRequest = undefined;
}
