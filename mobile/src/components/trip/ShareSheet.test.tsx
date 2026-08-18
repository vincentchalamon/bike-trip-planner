/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import type { ReactElement } from 'react';
import { Text } from 'react-native';
import type { StageData } from '@btp/core';

jest.mock('../../api/trips', () => ({
  getTripShare: jest.fn(),
  createTripShare: jest.fn(),
  revokeTripShare: jest.fn(),
  buildShareUrl: (code: string) => `https://web.example/s/${code}`,
}));
jest.mock('expo-clipboard', () => ({ setStringAsync: jest.fn() }));
jest.mock('../../lib/share-image', () => ({
  captureAndShareInfographic: jest.fn().mockResolvedValue('file:///trip.png'),
}));

import * as Clipboard from 'expo-clipboard';
import { getTripShare, createTripShare, revokeTripShare } from '../../api/trips';
import { captureAndShareInfographic } from '../../lib/share-image';
import i18n from '../../i18n';
import { useTripStore } from '../../store/trip-store';
import { ShareSheet } from './ShareSheet';

const mockGet = getTripShare as jest.Mock;
const mockCreate = createTripShare as jest.Mock;
const mockRevoke = revokeTripShare as jest.Mock;
const mockClip = Clipboard.setStringAsync as jest.Mock;
const mockCapture = captureAndShareInfographic as jest.Mock;

function textOf(node: any): string[] {
  const kids = Array.isArray(node.props.children)
    ? node.props.children
    : [node.props.children];
  return kids.filter((c: unknown): c is string => typeof c === 'string');
}

function button(tree: any, label: string): any {
  return tree.root
    .findAllByProps({ accessibilityRole: 'button' })
    .find((b: any) =>
      b.findAllByType(Text).some((tx: any) => textOf(tx).includes(label)),
    );
}

function linkText(tree: any): string | null {
  const nodes = tree.root.findAllByProps({ testID: 'share-link-text' });
  return nodes.length > 0 ? textOf(nodes[0]).join('') : null;
}

let lastTree: any;
async function render(element: ReactElement): Promise<any> {
  await act(async () => {
    lastTree = TestRenderer.create(element);
  });
  return lastTree;
}

async function press(node: any): Promise<void> {
  await act(async () => {
    await node.props.onPress();
  });
}

function stage(overrides: Partial<StageData> = {}): StageData {
  const point = { lat: 0, lon: 0, ele: 0 };
  return {
    dayNumber: 1,
    distance: 50,
    elevation: 300,
    elevationLoss: 200,
    startPoint: point,
    endPoint: point,
    geometry: [],
    label: null,
    startLabel: null,
    endLabel: null,
    weather: null,
    alerts: [],
    pois: [],
    accommodations: [],
    selectedAccommodation: null,
    accommodationSearchRadiusKm: 5,
    isRestDay: false,
    supplyTimeline: [],
    events: [],
    ...overrides,
  };
}

beforeAll(async () => {
  await i18n.changeLanguage('fr');
});

beforeEach(() => {
  jest.clearAllMocks();
  jest.useFakeTimers();
  useTripStore.setState({
    title: 'Traversée des Alpes',
    stages: [stage()],
    startDate: null,
    endDate: null,
    sourceUrl: '',
    loading: false,
  });
});

afterEach(() => {
  act(() => {
    lastTree?.unmount();
    lastTree = undefined;
    useTripStore.getState().reset();
  });
  jest.useRealTimers();
});

describe('ShareSheet (#1048)', () => {
  it('creates then revokes the public link, toggling the displayed URL', async () => {
    mockGet.mockResolvedValue(null);
    mockCreate.mockResolvedValue({ shortCode: 'abc123' });
    mockRevoke.mockResolvedValue(true);

    const tree = await render(
      <ShareSheet visible onClose={jest.fn()} tripId="t1" />,
    );

    // No active share yet: the create button shows, no link is rendered.
    expect(linkText(tree)).toBeNull();
    expect(button(tree, 'Créer un lien')).toBeTruthy();

    await press(button(tree, 'Créer un lien'));
    expect(mockCreate).toHaveBeenCalledWith('t1');
    expect(linkText(tree)).toBe('https://web.example/s/abc123');

    await press(button(tree, 'Révoquer'));
    expect(mockRevoke).toHaveBeenCalledWith('t1');
    expect(linkText(tree)).toBeNull();
    expect(button(tree, 'Créer un lien')).toBeTruthy();
  });

  it('copies the link and shows the transient "copied" label', async () => {
    mockGet.mockResolvedValue({ shortCode: 'xyz789' });

    const tree = await render(
      <ShareSheet visible onClose={jest.fn()} tripId="t1" />,
    );
    expect(linkText(tree)).toBe('https://web.example/s/xyz789');

    await press(button(tree, 'Copier le lien'));
    expect(mockClip).toHaveBeenCalledWith('https://web.example/s/xyz789');
    expect(button(tree, 'Lien copié')).toBeTruthy();
  });

  it('copies the formatted text (with the link) and toggles the copied label', async () => {
    mockGet.mockResolvedValue({ shortCode: 'xyz789' });

    const tree = await render(
      <ShareSheet visible onClose={jest.fn()} tripId="t1" />,
    );

    await press(button(tree, 'Copier le texte'));
    const copied = mockClip.mock.calls[0][0] as string;
    expect(copied).toContain('Traversée des Alpes');
    expect(copied).toContain('Voir en ligne : https://web.example/s/xyz789');
    expect(button(tree, 'Texte copié')).toBeTruthy();
  });

  it('shares the infographic PNG via the capture helper', async () => {
    mockGet.mockResolvedValue(null);

    const tree = await render(
      <ShareSheet visible onClose={jest.fn()} tripId="t1" />,
    );
    await press(button(tree, "Partager l'image"));
    expect(mockCapture).toHaveBeenCalledTimes(1);
  });

  it('fetches the existing share only once across re-renders (hasFetched guard)', async () => {
    mockGet.mockResolvedValue(null);

    const tree = await render(
      <ShareSheet visible onClose={jest.fn()} tripId="t1" />,
    );
    expect(mockGet).toHaveBeenCalledTimes(1);

    // A re-render with the sheet still open must not refetch.
    await act(async () => {
      tree.update(<ShareSheet visible onClose={jest.fn()} tripId="t1" />);
    });
    expect(mockGet).toHaveBeenCalledTimes(1);
  });
});
