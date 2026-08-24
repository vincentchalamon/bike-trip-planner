/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import { Text } from 'react-native';
import i18n from '../../i18n';
import { useTripStore } from '../../store/trip-store';
import TripRoadbook from '../../../app/trip/[id]/index';

jest.mock('expo-router', () => ({
  Stack: { Screen: () => null },
  useLocalSearchParams: () => ({ id: 'trip-1' }),
  useRouter: () => ({ back: jest.fn(), push: jest.fn() }),
}));

jest.mock('../../hooks/use-trip-live', () => ({ useTripLive: jest.fn() }));

jest.mock('../../components/trip', () => ({
  ConfigSheet: () => null,
  RoadbookView: () => null,
  ShareSheet: () => null,
  SseStatusIndicator: () => null,
  TripMapView: jest.fn(() => null),
  TripTitleHeader: () => null,
}));

jest.mock('../../store/trip-cache', () => ({ readTripCache: jest.fn() }));
import { readTripCache } from '../../store/trip-cache';
const mockReadCache = readTripCache as jest.MockedFunction<typeof readTripCache>;

function texts(node: any): string[] {
  return node.root.findAllByType(Text).flatMap((t: any) => {
    const kids = Array.isArray(t.props.children) ? t.props.children : [t.props.children];
    return kids.filter((c: unknown): c is string => typeof c === 'string');
  });
}

async function render(): Promise<any> {
  let tree!: ReturnType<typeof TestRenderer.create>;
  await act(async () => {
    tree = TestRenderer.create(<TripRoadbook />);
    await Promise.resolve();
    await Promise.resolve();
  });
  return tree;
}

beforeAll(async () => {
  await i18n.changeLanguage('fr');
});

beforeEach(() => {
  mockReadCache.mockReset();
  useTripStore.setState({
    title: 'Traversée des Alpes',
    computing: false,
    loading: false,
    error: null,
  });
});

describe('TripRoadbook screen — synced freshness badge (#1147)', () => {
  it('shows the "synced X ago" badge when the cache has a syncedAt', async () => {
    mockReadCache.mockResolvedValue({
      detail: { title: 'Traversée des Alpes' },
      route: null,
      syncedAt: Date.now(),
    } as never);

    const tree = await render();
    expect(texts(tree).some((label) => label.startsWith('Synchronisé'))).toBe(true);
  });

  it('hides the badge when the cache resolves without a syncedAt (cache miss)', async () => {
    mockReadCache.mockResolvedValue(null);

    const tree = await render();
    expect(texts(tree).some((label) => label.startsWith('Synchronisé'))).toBe(false);
  });
});

describe('TripRoadbook screen — lazy map mount (#1176, ADR-057)', () => {
  it('mounts TripMapView only after the Map tab is opened, then keeps it mounted', async () => {
    mockReadCache.mockResolvedValue(null);
    const { TripMapView } = jest.requireMock('../../components/trip');
    TripMapView.mockClear();

    const tree = await render();
    // Roadbook is the default view: the map — and its eager useTripRoute() /route
    // fetch — must NOT mount for a rider who never taps "Carte" (ADR-057).
    expect(TripMapView).not.toHaveBeenCalled();
    expect(tree.root.findAllByType(TripMapView).length).toBe(0);

    // Open the Map tab via the SegmentedControl.
    const segmented = tree.root.findAll((n: any) => Array.isArray(n.props?.segments))[0];
    await act(async () => {
      segmented.props.onChange('map');
    });
    expect(TripMapView).toHaveBeenCalled();
    expect(tree.root.findAllByType(TripMapView).length).toBe(1);

    // Switch back to Roadbook: the map stays mounted (never torn down / re-mounted).
    await act(async () => {
      segmented.props.onChange('roadbook');
    });
    expect(tree.root.findAllByType(TripMapView).length).toBe(1);
  });
});
