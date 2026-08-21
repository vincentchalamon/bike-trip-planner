/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import { Linking, Text } from 'react-native';
import type { ReactElement } from 'react';
import i18n from '../../i18n';

jest.mock('../../hooks/use-in-ride-search', () => ({
  useInRideSearch: jest.fn(),
}));

import { useInRideSearch } from '../../hooks/use-in-ride-search';
import { InRidePanel } from './InRidePanel';

const mockHook = useInRideSearch as jest.MockedFunction<typeof useInRideSearch>;

const BASE = {
  isSearching: false,
  errorKey: null,
  recap: null,
  activeCategory: null,
  canWiden: false,
  search: jest.fn(),
  widen: jest.fn(),
};

function hookReturns(over: Partial<ReturnType<typeof useInRideSearch>> = {}) {
  mockHook.mockReturnValue({ ...BASE, search: jest.fn(), widen: jest.fn(), ...over });
}

const grantedAt = {
  permission: 'granted' as const,
  position: { latitude: 45, longitude: 6 } as never,
};

async function render(
  element: ReactElement,
): Promise<ReturnType<typeof TestRenderer.create>> {
  let out!: ReturnType<typeof TestRenderer.create>;
  await act(async () => {
    out = TestRenderer.create(element);
  });
  return out;
}

async function press(node: { props: { onPress: () => unknown } }): Promise<void> {
  await act(async () => {
    node.props.onPress();
  });
}

function texts(tree: ReturnType<typeof TestRenderer.create>): string[] {
  return tree.root.findAllByType(Text).flatMap((n: any) => {
    const kids = Array.isArray(n.props.children) ? n.props.children : [n.props.children];
    return kids.filter((c: unknown): c is string => typeof c === 'string');
  });
}

function byLabel(tree: ReturnType<typeof TestRenderer.create>, label: string) {
  return tree.root.findAll((n: any) => n.props.accessibilityLabel === label);
}

const CATEGORIES = [
  'water',
  'shelter',
  'food',
  'resupply',
  'mechanic',
  'health',
  'train',
  'charging',
] as const;

beforeAll(async () => {
  await i18n.changeLanguage('fr');
});

beforeEach(() => {
  jest.clearAllMocks();
  hookReturns();
});

describe('InRidePanel (#1150)', () => {
  it('renders one chip per intent, resupply included', async () => {
    const tree = await render(<InRidePanel tripId="t1" location={grantedAt} />);
    for (const cat of CATEGORIES) {
      expect(byLabel(tree, i18n.t(`trip.inRide.search.${cat}`)).length).toBeGreaterThan(0);
    }
    expect(byLabel(tree, i18n.t('trip.inRide.search.resupply')).length).toBeGreaterThan(0);
  });

  it('searches from the current GPS fix when a chip is tapped', async () => {
    const search = jest.fn();
    hookReturns({ search });
    const tree = await render(<InRidePanel tripId="t1" location={grantedAt} />);

    const chip = byLabel(tree, i18n.t('trip.inRide.search.water'))[0];
    await press(chip);

    expect(search).toHaveBeenCalledWith('water', { lat: 45, lon: 6 });
  });

  it('disables the chips until a GPS fix is available', async () => {
    const search = jest.fn();
    hookReturns({ search });
    const tree = await render(
      <InRidePanel tripId="t1" location={{ permission: 'granted', position: null }} />,
    );
    const chip = byLabel(tree, i18n.t('trip.inRide.search.water'))[0];
    expect(chip.props.accessibilityState.disabled).toBe(true);
  });

  it('renders the recap and each POI card, flagging unverified opening hours', async () => {
    hookReturns({
      recap: {
        category: 'water',
        radiusMeters: 3000,
        totalFound: 1,
        capReached: false,
        outOfCoverage: false,
        pois: [
          {
            name: 'Fontaine du bourg',
            category: 'water',
            lat: 45,
            lon: 6,
            distance_m: 240,
            deeplink: 'https://maps.example/1',
            opening_hours_today: null,
            warning: 'hours_unverified',
          } as never,
        ],
      },
    });
    const tree = await render(<InRidePanel tripId="t1" location={grantedAt} />);

    expect(texts(tree)).toContain(i18n.t('trip.inRide.recap.found', { count: 1 }));
    expect(texts(tree)).toContain('Fontaine du bourg');
    expect(texts(tree)).toContain(i18n.t('trip.inRide.noOpeningHours'));
  });

  it('hands off to the maps app when Open in Maps is tapped', async () => {
    const openURL = jest.spyOn(Linking, 'openURL').mockResolvedValue(true as never);
    hookReturns({
      recap: {
        category: 'water',
        radiusMeters: 3000,
        totalFound: 1,
        capReached: false,
        outOfCoverage: false,
        pois: [
          {
            name: 'Fontaine',
            category: 'water',
            lat: 45,
            lon: 6,
            distance_m: 100,
            deeplink: 'https://maps.example/go',
          } as never,
        ],
      },
    });
    const tree = await render(<InRidePanel tripId="t1" location={grantedAt} />);

    const open = byLabel(tree, i18n.t('trip.inRide.openInMaps'))[0];
    await press(open);
    expect(openURL).toHaveBeenCalledWith('https://maps.example/go');
    openURL.mockRestore();
  });

  it('does not call Linking.openURL for a non-http(s) deeplink', async () => {
    const openURL = jest.spyOn(Linking, 'openURL').mockResolvedValue(true as never);
    hookReturns({
      recap: {
        category: 'water',
        radiusMeters: 3000,
        totalFound: 1,
        capReached: false,
        outOfCoverage: false,
        pois: [
          {
            name: 'Fontaine',
            category: 'water',
            lat: 45,
            lon: 6,
            distance_m: 100,
            deeplink: 'intent://evil',
          } as never,
        ],
      },
    });
    const tree = await render(<InRidePanel tripId="t1" location={grantedAt} />);

    const open = byLabel(tree, i18n.t('trip.inRide.openInMaps'))[0];
    await press(open);
    expect(openURL).not.toHaveBeenCalled();
    openURL.mockRestore();
  });

  it('shows the widen affordance and replays the search when tapped', async () => {
    const widen = jest.fn();
    hookReturns({
      canWiden: true,
      widen,
      recap: {
        category: 'water',
        radiusMeters: 3000,
        totalFound: 0,
        capReached: false,
        outOfCoverage: false,
        pois: [],
      },
    });
    const tree = await render(<InRidePanel tripId="t1" location={grantedAt} />);

    const btn = byLabel(tree, i18n.t('trip.inRide.widenSearch'))[0];
    expect(btn).toBeDefined();
    await press(btn);
    expect(widen).toHaveBeenCalledWith({ lat: 45, lon: 6 });
  });

  it('surfaces the localized rate-limit message', async () => {
    hookReturns({ errorKey: 'errorRateLimit' });
    const tree = await render(<InRidePanel tripId="t1" location={grantedAt} />);
    expect(texts(tree)).toContain(i18n.t('trip.inRide.errorRateLimit'));
  });
});
