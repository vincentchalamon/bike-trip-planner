import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, within, cleanup } from "@testing-library/react";
import "@testing-library/jest-dom/vitest";
import { NextIntlClientProvider } from "next-intl";
import messages from "../../../../messages/fr.json";
import { MOCK_SHARED_TRIP } from "./shared-trip.fixture";
import type { SharedTripDetail } from "@/lib/api/client";
import { TooltipProvider } from "@/components/ui/tooltip";
import { useTripStore } from "@/store/trip-store";

const fetchSharedTripRoute = vi.fn();

vi.mock("@/lib/api/client", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/api/client")>()),
  fetchSharedTripRoute: (code: string) => fetchSharedTripRoute(code),
}));

vi.mock("next/navigation", () => ({
  usePathname: () => "/s/Br3t4gn3",
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), prefetch: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
}));

// maplibre needs WebGL, which jsdom does not have. The map is not what these
// tests are about.
vi.mock("@/components/Map/MapPanel", () => ({
  MapPanel: () => <div data-testid="map-panel" />,
}));

import SharedTripPage from "./shared-trip-page";

const trip = MOCK_SHARED_TRIP as unknown as SharedTripDetail;

function renderPage() {
  return render(
    <NextIntlClientProvider locale="fr" messages={messages} timeZone="UTC">
      <TooltipProvider>
        <SharedTripPage code="Br3t4gn3" trip={trip} />
      </TooltipProvider>
    </NextIntlClientProvider>,
  );
}

describe("SharedTripPage", () => {
  beforeEach(() => {
    vi.stubGlobal(
      "IntersectionObserver",
      class {
        observe() {}
        disconnect() {}
      },
    );
    fetchSharedTripRoute.mockResolvedValue(null);
  });

  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    vi.unstubAllGlobals();
  });

  it("renders the title, summary, stages and read-only chrome from the trip prop", () => {
    renderPage();

    expect(
      screen.getByRole("heading", { name: "Tour de Bretagne" }),
    ).toBeInTheDocument();
    expect(screen.getByTestId("top-bar")).toBeInTheDocument();
    expect(
      within(screen.getByTestId("trip-actions")).getByRole("button", {
        name: "Télécharger le GPX complet",
      }),
    ).toBeInTheDocument();
    expect(screen.getByTestId("total-distance")).toBeInTheDocument();
    expect(screen.getByTestId("stage-card-1")).toBeInTheDocument();
    expect(screen.getByTestId("stage-card-2")).toBeInTheDocument();
    expect(screen.getByTestId("read-only-banner")).toBeInTheDocument();
    expect(screen.getByTestId("section-footer")).toBeInTheDocument();
  });

  it("offers no stage editing controls", () => {
    renderPage();

    expect(screen.getByTestId("stage-card-1")).toBeInTheDocument();
    expect(screen.queryAllByTestId(/^add-stage-button-/)).toHaveLength(0);
    expect(screen.queryAllByTestId(/^add-rest-day-button-/)).toHaveLength(0);
  });

  it("loads the route geometry client-side by short code", () => {
    renderPage();

    expect(fetchSharedTripRoute).toHaveBeenCalledWith("Br3t4gn3");
  });

  it("hydrates the store read-only and clears it on unmount", () => {
    const { unmount } = renderPage();

    expect(useTripStore.getState().stages).toHaveLength(2);
    expect(useTripStore.getState().trip).toBeNull();

    unmount();

    expect(useTripStore.getState().stages).toHaveLength(0);
  });
});
