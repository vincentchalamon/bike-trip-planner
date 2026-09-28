import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, act } from "@testing-library/react";
import "@testing-library/jest-dom/vitest";
import { fetchTrips } from "@/lib/api/client";
import TripsPage from "./page";

vi.mock("next-intl", () => ({
  useTranslations: () => (key: string) => key,
  useLocale: () => "en",
}));
vi.mock("@/lib/api/client", () => ({
  fetchTrips: vi.fn(),
  deleteTrip: vi.fn(),
}));
vi.mock("@/components/trip-card", () => ({
  TripCard: ({ trip }: { trip: { title: string } }) => <p>{trip.title}</p>,
}));
vi.mock("@/components/trips-empty-state", () => ({
  TripsEmptyState: () => null,
}));

type Page = { member: { id: string; title: string }[]; totalItems: number };

function deferred() {
  let resolve!: (value: Page) => void;
  const promise = new Promise<Page>((r) => (resolve = r));
  return { promise, resolve };
}

const page = (title: string): Page => ({
  member: [{ id: title, title }],
  totalItems: 1,
});

describe("TripsPage", () => {
  beforeEach(() => vi.clearAllMocks());

  it("never lets an older response overwrite a newer filter's result", async () => {
    const requests: ReturnType<typeof deferred>[] = [];
    vi.mocked(fetchTrips).mockImplementation(() => {
      const request = deferred();
      requests.push(request);
      return request.promise as ReturnType<typeof fetchTrips>;
    });

    render(<TripsPage />);
    await act(async () => requests[0]!.resolve(page("initial")));
    expect(screen.getByText("initial")).toBeInTheDocument();

    const [from, until] = [
      screen.getByLabelText("filterFrom"),
      screen.getByLabelText("filterUntil"),
    ];
    fireEvent.change(from!, { target: { value: "2026-06-01" } });
    fireEvent.change(until!, { target: { value: "2026-06-30" } });
    const [older, newer] = requests.slice(-2);

    await act(async () => newer!.resolve(page("newer")));
    await act(async () => older!.resolve(page("older")));

    expect(screen.getByText("newer")).toBeInTheDocument();
    expect(screen.queryByText("older")).not.toBeInTheDocument();
  });
});
