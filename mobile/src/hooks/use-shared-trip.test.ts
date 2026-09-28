/// <reference types="jest" />
import { runSharedTrip } from './use-shared-trip';
import { useTripStore } from '../store/trip-store';

jest.mock('../api/trips', () => ({
  fetchSharedTrip: jest.fn(),
  fetchSharedTripRoute: jest.fn(),
}));
import { fetchSharedTrip, fetchSharedTripRoute } from '../api/trips';
const mockShared = fetchSharedTrip as jest.MockedFunction<typeof fetchSharedTrip>;
const mockRoute = fetchSharedTripRoute as jest.MockedFunction<
  typeof fetchSharedTripRoute
>;

beforeEach(() => {
  jest.clearAllMocks();
  useTripStore.getState().reset();
});

describe('runSharedTrip', () => {
  // The shared hydrate was its own copy and forgot the preferences, so a shared
  // e-bike trip read as a regular one departing at the default hour.
  it('hydrates the whole trip settings and the stages through the core mapper', async () => {
    mockShared.mockResolvedValue({
      title: 'Shared',
      startDate: '2026-08-01T00:00:00+02:00',
      endDate: '2026-08-02T00:00:00+02:00',
      ebikeMode: true,
      departureHour: 6,
      enabledAccommodationTypes: ['camp_site'],
      stages: [{ stageId: 's1', dayNumber: 1, onCycleNetwork: 0.6 }],
    } as never);
    mockRoute.mockResolvedValue(null);

    const s = useTripStore.getState();
    await runSharedTrip('abc', s, () => false);

    const state = useTripStore.getState();
    expect(state.title).toBe('Shared');
    expect(state.startDate).toBe('2026-08-01');
    expect(state.endDate).toBe('2026-08-02');
    expect(state.ebikeMode).toBe(true);
    expect(state.departureHour).toBe(6);
    expect(state.enabledAccommodationTypes).toEqual(['camp_site']);
    expect(state.fatigueFactor).toBe(0.9);
    expect(state.stages[0]!.onCycleNetwork).toBe(0.6);
    expect(state.tripId).toBeNull();
  });
});
