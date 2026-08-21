/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import { Text, View } from 'react-native';
import type { ReactElement } from 'react';
import * as Location from 'expo-location';
import i18n from '../../i18n';
import { useOfflineStore } from '../../store/offline-store';
import { InRideView } from './InRideView';

jest.mock('expo-location', () => ({
  Accuracy: { Balanced: 3 },
  requestForegroundPermissionsAsync: jest.fn(),
  watchPositionAsync: jest.fn(),
}));

const requestPerm = Location.requestForegroundPermissionsAsync as jest.Mock;
const watch = Location.watchPositionAsync as jest.Mock;

function texts(node: any): string[] {
  return node.root.findAllByType(Text).flatMap((t: any) => {
    const kids = Array.isArray(t.props.children) ? t.props.children : [t.props.children];
    return kids.filter((c: unknown): c is string => typeof c === 'string');
  });
}

function queryByLabel(tree: any, label: string): any {
  return tree.root.findAll((n: any) => n.props.accessibilityLabel === label)[0] ?? null;
}

async function render(element: ReactElement): Promise<any> {
  let out: any;
  await act(async () => {
    out = TestRenderer.create(element);
    for (let i = 0; i < 8; i += 1) await Promise.resolve();
  });
  return out;
}

beforeAll(async () => {
  await i18n.changeLanguage('fr');
});

beforeEach(() => {
  requestPerm.mockReset();
  watch.mockReset();
  requestPerm.mockResolvedValue({ status: 'granted' });
  watch.mockResolvedValue({ remove: jest.fn() });
  useOfflineStore.setState({ isOnline: true });
});

describe('InRideView', () => {
  it('renders the help bubble and shows the current GPS position once a fix arrives', async () => {
    let callback: ((loc: { coords: unknown }) => void) | undefined;
    watch.mockImplementation(async (_opts: unknown, cb: (loc: { coords: unknown }) => void) => {
      callback = cb;
      return { remove: jest.fn() };
    });

    const tree = await render(<InRideView tripId="t1" />);
    expect(queryByLabel(tree, i18n.t('trip.inRide.helpA11y'))).not.toBeNull();

    await act(async () => {
      callback?.({ coords: { latitude: 45.5, longitude: 6.12345 } });
    });

    expect(queryByLabel(tree, i18n.t('trip.inRide.positionA11y'))).not.toBeNull();
    expect(texts(tree)).toContain('45.50000, 6.12345');
  });

  it('shows the offline badge only when connectivity is down', async () => {
    const online = await render(<InRideView tripId="t1" />);
    expect(queryByLabel(online, i18n.t('trip.inRide.offlineBadge'))).toBeNull();

    useOfflineStore.setState({ isOnline: false });
    const offline = await render(<InRideView tripId="t1" />);
    expect(queryByLabel(offline, i18n.t('trip.inRide.offlineBadge'))).not.toBeNull();
    expect(texts(offline)).toContain(i18n.t('trip.inRide.offlineHint'));
  });

  it('renders the denied empty state when the location permission is refused', async () => {
    requestPerm.mockResolvedValue({ status: 'denied' });
    const tree = await render(<InRideView tripId="t1" />);
    expect(texts(tree)).toContain(i18n.t('trip.inRide.locationDeniedTitle'));
    expect(watch).not.toHaveBeenCalled();
  });

  it('mounts the #1150 poiPanel slot when provided', async () => {
    const slot = (
      <View accessibilityLabel="poi-slot">
        <Text>slot</Text>
      </View>
    );
    const tree = await render(<InRideView tripId="t1" poiPanel={slot} />);
    expect(queryByLabel(tree, 'poi-slot')).not.toBeNull();
  });

  it('shows the disclaimer banner (maquette 08-in-ride, #1094)', async () => {
    const tree = await render(<InRideView tripId="t1" />);
    expect(texts(tree)).toContain(i18n.t('trip.inRide.disclaimerStrong'));
    expect(texts(tree)).toContain(i18n.t('trip.inRide.disclaimer'));
  });

  it('shows the connectivity status text, online or offline', async () => {
    const online = await render(<InRideView tripId="t1" />);
    expect(texts(online)).toContain(i18n.t('trip.inRide.onlineBadge'));

    useOfflineStore.setState({ isOnline: false });
    const offline = await render(<InRideView tripId="t1" />);
    expect(texts(offline)).toContain(i18n.t('trip.inRide.offlineBadge'));
  });
});
