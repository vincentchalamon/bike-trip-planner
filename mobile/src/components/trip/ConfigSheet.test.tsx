/// <reference types="jest" />
import { Alert, Text } from 'react-native';
import TestRenderer, { act } from 'react-test-renderer';
import type { ReactElement } from 'react';
import i18n from '../../i18n';
import { useTripStore } from '../../store/trip-store';

// Mock the mutation hook so the sheet's commits are observable jest fns without
// touching the network. The runners themselves are covered in mutations.test.ts.
const mockUpdateTitle = jest.fn(() => Promise.resolve(true));
const mockUpdatePacing = jest.fn(() => Promise.resolve(true));
const mockUpdateDates = jest.fn(() => Promise.resolve(true));
const mockUpdateAccommodationTypes = jest.fn(() => Promise.resolve(true));
jest.mock('../../hooks/use-trip-mutations', () => ({
  useTripMutations: () => ({
    updateTitle: mockUpdateTitle,
    updatePacing: mockUpdatePacing,
    updateDates: mockUpdateDates,
    updateAccommodationTypes: mockUpdateAccommodationTypes,
  }),
}));

import { ConfigSheet } from './ConfigSheet';

const t = (key: string, opts?: Record<string, unknown>) =>
  (i18n.t as (k: string, o?: Record<string, unknown>) => string)(key, opts);

function render(element: ReactElement): any {
  let out: any;
  act(() => {
    out = TestRenderer.create(element);
  });
  return out;
}

// A rendered Button (ui/Button) is the only Pressable that sets `busy` in its
// accessibilityState — use that to disambiguate it from presets/steppers/toggles.
function findButton(root: any, label: string): any {
  return root
    .findAll(
      (n: any) =>
        typeof n.props.onPress === 'function' &&
        n.props.accessibilityState != null &&
        'busy' in n.props.accessibilityState,
    )
    .find((b: any) =>
      b.findAllByType(Text).some((tn: any) => {
        const c = tn.props.children;
        return typeof c === 'string' && c === label;
      }),
    );
}

function findByA11y(root: any, label: string): any {
  return root.find(
    (n: any) => n.props.accessibilityLabel === label && typeof n.props.onPress === 'function',
  );
}

// Drive the themed track Slider: set a known track width via onLayout, then fire
// a responder grant at `locationX`. value = clamp(round((min + x/width*(max-min))/step)*step).
// For maxDistance (min 30, max 300, step 5): width 270, x 55 -> 85 km.
function dragSlider(root: any, label: string, locationX: number, width = 270): any {
  const s = root.find(
    (n: any) => n.props.accessibilityRole === 'adjustable' && n.props.accessibilityLabel === label,
  );
  act(() => {
    s.props.onLayout({ nativeEvent: { layout: { width } } });
  });
  act(() => {
    s.props.onResponderGrant({ nativeEvent: { locationX } });
  });
  return s;
}

function press(node: any) {
  act(() => {
    node.props.onPress();
  });
}

const P = { lat: 0, lon: 0, ele: 0 };

beforeEach(() => {
  jest.clearAllMocks();
  act(() => {
    useTripStore.getState().reset();
    useTripStore.setState({
    tripId: 't1',
    title: 'Trip',
    stages: [
      {
        dayNumber: 1,
        distance: 50,
        elevation: 100,
        elevationLoss: 0,
        startPoint: P,
        endPoint: { lat: 1, lon: 1, ele: 0 },
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
      },
    ],
    isLocked: false,
    maxDistancePerDay: 80,
    averageSpeed: 15,
    fatigueFactor: 0.9,
    elevationPenalty: 50,
    ebikeMode: false,
    departureHour: 8,
    enabledAccommodationTypes: ['hotel', 'hostel'],
    });
  });
});

function allTexts(root: any): string[] {
  return root.findAllByType(Text).flatMap((tn: any) => {
    const kids = Array.isArray(tn.props.children) ? tn.props.children : [tn.props.children];
    return kids.filter((c: unknown): c is string => typeof c === 'string');
  });
}

describe('ConfigSheet pacing live-preview', () => {
  it('previews the value locally without committing until confirmed', () => {
    const tree = render(<ConfigSheet tripId="t1" visible onClose={jest.fn()} />);
    dragSlider(tree.root, t('config.maxDistance'), 55);
    // Live preview: the displayed value moved from 80 to 85 km...
    expect(allTexts(tree.root)).toContain(t('config.valueKm', { value: 85 }));
    // ...but no destructive commit fired yet.
    expect(mockUpdatePacing).not.toHaveBeenCalled();
  });
});

describe('ConfigSheet destructive-confirm gating', () => {
  it('requires confirmation before committing a pacing recompute', () => {
    const spy = jest.spyOn(Alert, 'alert').mockImplementation(() => {});
    const onClose = jest.fn();
    const tree = render(<ConfigSheet tripId="t1" visible onClose={onClose} />);
    dragSlider(tree.root, t('config.maxDistance'), 55);

    const recompute = findButton(tree.root, t('config.recompute'));
    expect(recompute).toBeDefined();
    press(recompute!);

    // Confirmation is shown; nothing committed yet.
    expect(spy).toHaveBeenCalledTimes(1);
    expect(mockUpdatePacing).not.toHaveBeenCalled();

    // Invoke the destructive confirm button passed to Alert.alert.
    const buttons = (spy.mock.calls[0] as any)[2] as { style?: string; onPress?: () => void }[];
    const confirm = buttons.find((b) => b.style === 'destructive');
    act(() => confirm?.onPress?.());

    expect(mockUpdatePacing).toHaveBeenCalledTimes(1);
    expect((mockUpdatePacing.mock.calls[0] as any)[0]).toMatchObject({
      maxDistancePerDay: 85,
    });
    // The diff baseline is armed so the roadbook can highlight the re-split.
    expect(useTripStore.getState().diffBaseline).not.toBeNull();
    expect(onClose).toHaveBeenCalled();
    spy.mockRestore();
  });

  it('disarms the diff baseline when the destructive commit fails', async () => {
    // A malformed date (free-text field) → backend 422 → the runner resolves
    // false with no trip_ready to follow. The armed baseline must be cleaned up.
    mockUpdatePacing.mockResolvedValueOnce(false);
    const spy = jest.spyOn(Alert, 'alert').mockImplementation(() => {});
    const tree = render(<ConfigSheet tripId="t1" visible onClose={jest.fn()} />);
    dragSlider(tree.root, t('config.maxDistance'), 55);
    press(findButton(tree.root, t('config.recompute'))!);
    const buttons = (spy.mock.calls[0] as any)[2] as { style?: string; onPress?: () => void }[];
    const confirm = buttons.find((b) => b.style === 'destructive');
    await act(async () => {
      confirm?.onPress?.();
      await Promise.resolve();
    });
    expect(mockUpdatePacing).toHaveBeenCalledTimes(1);
    expect(useTripStore.getState().diffBaseline).toBeNull();
    spy.mockRestore();
  });

  it('cancelling the confirmation commits nothing', () => {
    const spy = jest.spyOn(Alert, 'alert').mockImplementation(() => {});
    const tree = render(<ConfigSheet tripId="t1" visible onClose={jest.fn()} />);
    dragSlider(tree.root, t('config.maxDistance'), 55);
    press(findButton(tree.root, t('config.recompute'))!);
    const buttons = (spy.mock.calls[0] as any)[2] as { style?: string; onPress?: () => void }[];
    const cancel = buttons.find((b) => b.style === 'cancel');
    act(() => cancel?.onPress?.());
    expect(mockUpdatePacing).not.toHaveBeenCalled();
    expect(useTripStore.getState().diffBaseline).toBeNull();
    spy.mockRestore();
  });
});

describe('ConfigSheet accommodation types (min 1)', () => {
  it('toggles a type off when more than one is enabled', () => {
    const tree = render(<ConfigSheet tripId="t1" visible onClose={jest.fn()} />);
    press(findByA11y(tree.root, t('config.type_hotel')));
    expect(mockUpdateAccommodationTypes).toHaveBeenCalledWith(['hostel']);
  });

  it('refuses to disable the last enabled type', () => {
    act(() => {
      useTripStore.setState({ enabledAccommodationTypes: ['hotel'] });
    });
    const tree = render(<ConfigSheet tripId="t1" visible onClose={jest.fn()} />);
    const toggle = tree.root.find(
      (n: any) =>
        n.props.accessibilityRole === 'switch' &&
        n.props.accessibilityLabel === t('config.type_hotel'),
    );
    // The last enabled type is disabled; pressing it is a no-op guard.
    expect(toggle.props.disabled).toBe(true);
    press(toggle);
    expect(mockUpdateAccommodationTypes).not.toHaveBeenCalled();
  });
});
