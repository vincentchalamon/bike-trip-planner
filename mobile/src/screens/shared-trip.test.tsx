/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import { createElement } from 'react';
import i18n from '../i18n';

// The share screen pulls in the trip barrel (TripMapView/RoadbookView), which
// transitively imports the native maplibre module — stub it so jest can load.
jest.mock('@maplibre/maplibre-react-native', () => ({
  Camera: () => null,
  GeoJSONSource: ({ children }: { children?: unknown }) => children ?? null,
  Layer: () => null,
  Map: ({ children }: { children?: unknown }) => children ?? null,
}));

jest.mock('expo-router', () => ({
  Stack: { Screen: () => null },
  useLocalSearchParams: () => ({ code: 'AB12cd' }),
  useRouter: () => ({ push: jest.fn(), back: jest.fn() }),
}));

// Mock only the anonymous share fetches; keep every real trip-api export so the
// mutation hooks RoadbookView loads still resolve.
jest.mock('../api/trips', () => {
  const actual = jest.requireActual('../api/trips');
  return {
    ...actual,
    fetchSharedTrip: jest.fn(),
    fetchSharedTripRoute: jest.fn(),
    fetchSharedTripExport: jest.fn(),
  };
});
import { fetchSharedTrip, fetchSharedTripRoute } from '../api/trips';
import { useTripStore } from '../store/trip-store';
import SharedTrip from '../../app/s/[code]';

const mockFetch = fetchSharedTrip as jest.MockedFunction<typeof fetchSharedTrip>;
const mockFetchRoute = fetchSharedTripRoute as jest.MockedFunction<
  typeof fetchSharedTripRoute
>;

const SHARED_DETAIL = {
  title: 'Tour du Vercors',
  startDate: '2026-06-01T00:00:00+00:00',
  endDate: '2026-06-02T00:00:00+00:00',
  isLocked: false,
  outOfZone: false,
  stages: [
    {
      dayNumber: 1,
      distance: 42,
      elevation: 500,
      elevationLoss: 480,
      startPoint: { lat: 45, lon: 5, ele: 200 },
      endPoint: { lat: 45.1, lon: 5.1, ele: 220 },
      isRestDay: false,
    },
    {
      dayNumber: 2,
      distance: 38,
      elevation: 300,
      elevationLoss: 320,
      startPoint: { lat: 45.1, lon: 5.1, ele: 220 },
      endPoint: { lat: 45.2, lon: 5.2, ele: 210 },
      isRestDay: false,
    },
  ],
} as unknown as Awaited<ReturnType<typeof fetchSharedTrip>>;

function allTexts(root: any): string[] {
  return root
    .findAll((n: any) => typeof n.type === 'string' && n.type === 'Text')
    .flatMap((n: any) => {
      const c = n.props?.children;
      return typeof c === 'string' ? [c] : [];
    });
}

function findByA11yLabel(root: any, label: string): any[] {
  return root.findAll((n: any) => n.props?.accessibilityLabel === label);
}

let renderer: any;
async function render(): Promise<any> {
  await act(async () => {
    renderer = TestRenderer.create(createElement(SharedTrip));
  });
  return renderer.root;
}

beforeAll(async () => {
  await i18n.changeLanguage('fr');
});

beforeEach(() => {
  jest.clearAllMocks();
  mockFetchRoute.mockResolvedValue(null);
  useTripStore.getState().reset();
});

afterEach(() => {
  act(() => renderer?.unmount());
});

describe('Shared trip screen (#1177) — read-only consultation', () => {
  it('fetches the trip via the share short code and hydrates the store', async () => {
    mockFetch.mockResolvedValue(SHARED_DETAIL);

    await render();

    expect(mockFetch).toHaveBeenCalledWith('AB12cd');
    expect(useTripStore.getState().title).toBe('Tour du Vercors');
    expect(useTripStore.getState().stages).toHaveLength(2);
    // tripId stays null so the map never hits the auth-gated /trips/{id}/route.
    expect(useTripStore.getState().tripId).toBeNull();
  });

  it('shows the "shared view / read only" banner', async () => {
    mockFetch.mockResolvedValue(SHARED_DETAIL);

    const root = await render();

    expect(allTexts(root)).toContain(i18n.t('sharePage.readOnlyBanner'));
  });

  it('exposes no edit affordances (no insert rows, no in-ride FAB)', async () => {
    mockFetch.mockResolvedValue(SHARED_DETAIL);

    const root = await render();

    // Two stages would render a "+étape" insert row between them if editable.
    expect(allTexts(root)).not.toContain(i18n.t('trip.edit.addStage'));
    expect(findByA11yLabel(root, i18n.t('trip.rideCtaA11y'))).toHaveLength(0);
  });

  it('opts its Screen out of the bottom safe-area inset (#1217): reuses TripMap, would double-inset', async () => {
    // The shared view renders TripMapView/RoadbookView like the roadbook, whose
    // FAB owns insets.bottom — so its Screen must not also pad the bottom. Guard
    // the opt-out: the SafeAreaView `edges` must exclude 'bottom'.
    mockFetch.mockResolvedValue(SHARED_DETAIL);

    const root = await render();

    const safeAreas = root.findAll((n: any) => Array.isArray(n.props?.edges));
    expect(safeAreas.length).toBeGreaterThan(0);
    for (const node of safeAreas) {
      expect(node.props.edges).not.toContain('bottom');
    }
  });

  it('does not let a stage row tap through to the auth-gated live flow (#1177 review)', async () => {
    // A shared stage card must be inert: no onPress → no button role, no
    // open-stage a11y label. Otherwise tapping pushes /trip/<shareCode>/stage/...
    // and hits the authenticated /trips/{id}/detail with the share code as an id.
    mockFetch.mockResolvedValue(SHARED_DETAIL);

    const root = await render();

    expect(findByA11yLabel(root, i18n.t('trip.openStageA11y', { day: 1 }))).toHaveLength(0);
    expect(findByA11yLabel(root, i18n.t('trip.openStageA11y', { day: 2 }))).toHaveLength(0);
  });

  it('renders the invalid/revoked error copy when the link does not resolve', async () => {
    mockFetch.mockResolvedValue(null);

    const root = await render();

    expect(allTexts(root)).toContain(i18n.t('sharePage.error'));
  });
});
