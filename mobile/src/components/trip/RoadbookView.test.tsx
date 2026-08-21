/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import { EMPTY_RESUPPLY } from '@btp/core';
import type { ReactElement } from 'react';
import type { StageData } from '@btp/core';
import i18n from '../../i18n';
import { fr } from '../../i18n/resources/fr';
import { useTripStore } from '../../store/trip-store';
import { todayUtc } from './roadbook-dates';

const mockPush = jest.fn();
jest.mock('expo-router', () => ({ useRouter: () => ({ push: mockPush }) }));

jest.mock('../../hooks/use-trip-mutations', () => ({
  useTripMutations: () => ({
    addStage: jest.fn(),
    insertRestDay: jest.fn(),
    deleteStage: jest.fn(),
  }),
}));

import { RoadbookView } from './RoadbookView';

function queryByLabel(tree: any, label: string): any {
  const found = tree.root.findAll(
    (n: any) => n.props.accessibilityLabel === label,
  );
  return found[0] ?? null;
}

const addStageA11y = fr.trip.edit.addStageA11y.replace('{{day}}', '1');
const deleteA11y = fr.trip.deleteA11y.replace('{{day}}', '1');

function stage(overrides: Partial<StageData> = {}): StageData {
  const point = { lat: 0, lon: 0, ele: 0 };
  return {
    dayNumber: 1,
    distance: 50,
    elevation: 100,
    elevationLoss: 0,
    startPoint: point,
    endPoint: point,
    geometry: [],
    label: null,
    startLabel: 'Paris',
    endLabel: 'Lyon',
    weather: null,
    alerts: [],
    resupply: EMPTY_RESUPPLY,
    accommodations: [],
    selectedAccommodation: null,
    accommodationSearchRadiusKm: 10,
    isRestDay: false,
    supplyTimeline: [],
    events: [],
    ...overrides,
  };
}

function render(element: ReactElement): any {
  let out: any;
  act(() => {
    out = TestRenderer.create(element);
  });
  return out;
}

describe('RoadbookView navigation', () => {
  beforeAll(async () => {
    await i18n.changeLanguage('fr');
  });

  beforeEach(() => {
    mockPush.mockClear();
    act(() => {
      useTripStore.getState().reset();
      useTripStore.setState({
        stages: [stage({ dayNumber: 1 }), stage({ dayNumber: 2 })],
      });
    });
  });

  it('pushes the stage detail route when a stage summary is tapped', () => {
    const tree = render(<RoadbookView id="trip-42" />);
    const label = i18n.t('trip.openStageA11y', { day: 2 });
    const summary = tree.root.find(
      (node: any) =>
        node.props.accessibilityLabel === label &&
        typeof node.props.onPress === 'function',
    );

    act(() => summary.props.onPress());

    expect(mockPush).toHaveBeenCalledWith('/trip/trip-42/stage/1');
  });

  it('pushes the in-ride route when the "En selle" FAB is tapped', () => {
    const tree = render(<RoadbookView id="trip-42" />);
    const fab = queryByLabel(tree, i18n.t('trip.rideCtaA11y'));
    expect(fab).not.toBeNull();

    act(() => fab.props.onPress());

    expect(mockPush).toHaveBeenCalledWith('/trip/trip-42/in-ride');
  });
});

describe('RoadbookView edit affordances by lifecycle', () => {
  beforeAll(async () => {
    await i18n.changeLanguage('fr');
  });

  beforeEach(() => {
    act(() => {
      useTripStore.getState().reset();
      useTripStore.setState({
        stages: [stage({ dayNumber: 1 }), stage({ dayNumber: 2 })],
        isLocked: false,
        loading: false,
      });
    });
  });

  it('shows insertion rows, delete and the in-ride FAB on an editable (undated) trip', () => {
    const tree = render(<RoadbookView id="t1" />);
    expect(queryByLabel(tree, addStageA11y)).not.toBeNull();
    expect(queryByLabel(tree, deleteA11y)).not.toBeNull();
    expect(queryByLabel(tree, i18n.t('trip.rideCtaA11y'))).not.toBeNull();
  });

  it('hides every edit affordance when the trip is in the past (read-only)', () => {
    act(() =>
      useTripStore.setState({ startDate: '2000-01-01', endDate: '2000-01-02' }),
    );
    const tree = render(<RoadbookView id="t1" />);
    expect(queryByLabel(tree, addStageA11y)).toBeNull();
    expect(queryByLabel(tree, deleteA11y)).toBeNull();
    expect(queryByLabel(tree, i18n.t('trip.rideCtaA11y'))).toBeNull();
  });

  it('hides every edit affordance when the trip is ongoing (read-only)', () => {
    const today = todayUtc();
    act(() => useTripStore.setState({ startDate: today, endDate: today }));
    const tree = render(<RoadbookView id="t1" />);
    expect(queryByLabel(tree, addStageA11y)).toBeNull();
    expect(queryByLabel(tree, deleteA11y)).toBeNull();
    expect(queryByLabel(tree, i18n.t('trip.rideCtaA11y'))).toBeNull();
  });

  it('hides edit affordances when the backend locked the trip', () => {
    act(() => useTripStore.setState({ isLocked: true }));
    const tree = render(<RoadbookView id="t1" />);
    expect(queryByLabel(tree, addStageA11y)).toBeNull();
    expect(queryByLabel(tree, deleteA11y)).toBeNull();
  });

  it('makes the no-dates banner open the dates config when pressed', () => {
    const onConfigureDates = jest.fn();
    const tree = render(
      <RoadbookView id="t1" onConfigureDates={onConfigureDates} />,
    );
    const banner = queryByLabel(tree, i18n.t('trip.banners.noDatesA11y'));
    expect(banner).not.toBeNull();
    act(() => banner.props.onPress());
    expect(onConfigureDates).toHaveBeenCalledTimes(1);
  });
});
