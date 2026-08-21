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
  TripMapView: () => null,
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
