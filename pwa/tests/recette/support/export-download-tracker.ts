import type { Page, Route } from "@playwright/test";

const GPX_BODY =
  '<?xml version="1.0"?><gpx><trk><trkseg><trkpt lat="44.7" lon="4.5"><ele>280</ele></trkpt></trkseg></trk></gpx>';

const STAGE_GPX_PATTERN = "**/trips/*/stages/*.gpx";
const TRIP_GPX_PATTERN = "**/trips/*.gpx";
const STAGE_FIT_PATTERN = "**/trips/*/stages/*.fit";

const capturedRequests = {
  stageGpx: [] as string[],
  tripGpx: [] as string[],
  stageFit: [] as string[],
  stageExport: [] as string[],
};

function resetRequests(target: keyof typeof capturedRequests): void {
  capturedRequests[target].length = 0;
}

function fulfillGpx(route: Route, requests: string[]): Promise<void> {
  requests.push(route.request().url());
  return route.fulfill({
    status: 200,
    contentType: "application/gpx+xml",
    body: GPX_BODY,
  });
}

export function resetExportDownloadTracker(): void {
  resetRequests("stageGpx");
  resetRequests("tripGpx");
  resetRequests("stageFit");
  resetRequests("stageExport");
}

export async function trackStageGpxDownload(page: Page): Promise<void> {
  resetRequests("stageGpx");
  await page.route(
    STAGE_GPX_PATTERN,
    (route) => fulfillGpx(route, capturedRequests.stageGpx),
    { times: 1 },
  );
}

export async function trackTripGpxDownload(page: Page): Promise<void> {
  resetRequests("tripGpx");
  await page.route(
    TRIP_GPX_PATTERN,
    (route) => fulfillGpx(route, capturedRequests.tripGpx),
    { times: 1 },
  );
}

export async function trackStageFitDownload(page: Page): Promise<void> {
  resetRequests("stageFit");
  await page.route(
    STAGE_FIT_PATTERN,
    (route) => {
      capturedRequests.stageFit.push(route.request().url());
      return route.fulfill({
        status: 200,
        contentType: "application/octet-stream",
        body: "",
      });
    },
    { times: 1 },
  );
}

export async function trackStageExportDownload(
  page: Page,
  extension: string,
): Promise<void> {
  resetRequests("stageExport");
  await page.route(
    `**/trips/*/stages/*.${extension}`,
    (route) => {
      capturedRequests.stageExport.push(route.request().url());
      return route.fulfill({
        status: 200,
        contentType:
          extension === "gpx" ? "application/gpx+xml" : "application/octet-stream",
        body: extension === "gpx" ? GPX_BODY : "",
      });
    },
    { times: 1 },
  );
}

export function getTrackedStageGpxRequests(): string[] {
  return capturedRequests.stageGpx;
}

export function getTrackedTripGpxRequests(): string[] {
  return capturedRequests.tripGpx;
}

export function getTrackedStageFitRequests(): string[] {
  return capturedRequests.stageFit;
}

export function getTrackedStageExportRequests(): string[] {
  return capturedRequests.stageExport;
}
