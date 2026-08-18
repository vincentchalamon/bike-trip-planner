/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import type { ReactElement } from 'react';
import { Alert, TextInput } from 'react-native';
import type { StageData } from '@btp/core';
import i18n from '../../i18n';
import { useTripStore } from '../../store/trip-store';
import { useOfflineStore } from '../../store/offline-store';

jest.mock('expo-router', () => ({ useRouter: () => ({ push: jest.fn() }) }));

jest.mock('../../api/trips', () => ({
  deleteStage: jest.fn(),
  createStage: jest.fn(),
  updateStageDistance: jest.fn(),
  moveStage: jest.fn(),
  insertRestDay: jest.fn(),
  setStageAccommodation: jest.fn(),
  addPoiWaypoint: jest.fn(),
  scanAccommodations: jest.fn(),
  applyBatchRecompute: jest.fn(),
  analyzeTrip: jest.fn(),
  updateTripConfig: jest.fn(),
  duplicateTrip: jest.fn(),
  deleteTrip: jest.fn(),
}));

import { insertRestDay, updateStageDistance } from '../../api/trips';
import { RoadbookView } from './RoadbookView';

const mock = <T extends (...args: never[]) => unknown>(fn: T) =>
  fn as unknown as jest.MockedFunction<T>;

function stage(dayNumber: number): StageData {
  const point = { lat: 0, lon: 0, ele: 0 };
  return {
    dayNumber,
    distance: 50,
    elevation: 100,
    elevationLoss: 0,
    startPoint: point,
    endPoint: { lat: 1, lon: 1, ele: 0 },
    geometry: [],
    label: null,
    startLabel: 'Paris',
    endLabel: 'Lyon',
    weather: null,
    alerts: [],
    pois: [],
    accommodations: [],
    selectedAccommodation: null,
    accommodationSearchRadiusKm: 10,
    isRestDay: false,
    supplyTimeline: [],
    events: [],
  };
}

function render(element: ReactElement): any {
  let out: any;
  act(() => {
    out = TestRenderer.create(element);
  });
  return out;
}

function press(tree: any, label: string): void {
  const node = tree.root.find(
    (n: any) =>
      n.props.accessibilityLabel === label &&
      typeof n.props.onPress === 'function',
  );
  act(() => node.props.onPress());
}

const restDayA11y = () => i18n.t('trip.edit.addRestDayA11y', { day: 1 });
const distanceA11y = () => i18n.t('trip.edit.editDistanceA11y', { day: 1 });

describe('RoadbookView inline edit wiring (#1044)', () => {
  let alertSpy: jest.SpyInstance;

  beforeAll(async () => {
    await i18n.changeLanguage('fr');
  });

  beforeEach(() => {
    jest.clearAllMocks();
    alertSpy = jest.spyOn(Alert, 'alert').mockImplementation(() => {});
    act(() => {
      useTripStore.getState().reset();
      useOfflineStore.setState({ isOnline: true });
      useTripStore.setState({
        stages: [stage(1), stage(2)],
        isLocked: false,
        outOfZone: false,
        startDate: '2026-08-01',
        endDate: '2026-08-02',
        loading: false,
      });
    });
  });

  afterEach(() => alertSpy.mockRestore());

  it('applies a rest-day insertion optimistically and calls the API', async () => {
    mock(insertRestDay).mockResolvedValue({ ok: true, status: 202 });
    const tree = render(<RoadbookView id="t1" />);

    press(tree, restDayA11y());
    // Optimistic insert is synchronous: the third stage is already present.
    expect(useTripStore.getState().stages).toHaveLength(3);

    await act(async () => {});
    expect(insertRestDay).toHaveBeenCalledWith('t1', 0);
    expect(useTripStore.getState().stages).toHaveLength(3);
    expect(alertSpy).not.toHaveBeenCalled();
  });

  it('rolls back the optimistic insert and alerts on a backend failure', async () => {
    mock(insertRestDay).mockResolvedValue({ ok: false, status: 409 });
    const tree = render(<RoadbookView id="t1" />);

    await act(async () => {
      (
        tree.root.find(
          (n: any) =>
            n.props.accessibilityLabel === restDayA11y() &&
            typeof n.props.onPress === 'function',
        ) as any
      ).props.onPress();
    });

    expect(useTripStore.getState().stages).toHaveLength(2);
    expect(alertSpy).toHaveBeenCalledWith(
      i18n.t('trip.edit.failedTitle'),
      i18n.t('trip.edit.reason.conflict'),
    );
  });

  it('gates edits while offline: no API call, offline alert', async () => {
    useOfflineStore.setState({ isOnline: false });
    const tree = render(<RoadbookView id="t1" />);

    await act(async () => {
      (
        tree.root.find(
          (n: any) =>
            n.props.accessibilityLabel === restDayA11y() &&
            typeof n.props.onPress === 'function',
        ) as any
      ).props.onPress();
    });

    expect(insertRestDay).not.toHaveBeenCalled();
    expect(useTripStore.getState().stages).toHaveLength(2);
    expect(alertSpy).toHaveBeenCalledWith(
      i18n.t('trip.edit.failedTitle'),
      i18n.t('trip.edit.reason.offline'),
    );
  });

  it('commits an inline distance edit to the API', async () => {
    mock(updateStageDistance).mockResolvedValue({ ok: true, status: 202 });
    const tree = render(<RoadbookView id="t1" />);

    press(tree, distanceA11y());
    const input = tree.root.find(
      (n: any) => n.props.accessibilityLabel === distanceA11y() && 'value' in n.props,
    );
    act(() => input.props.onChangeText('88'));
    press(tree, i18n.t('trip.edit.saveA11y'));

    await act(async () => {});
    expect(updateStageDistance).toHaveBeenCalledWith('t1', 0, 88);
  });

  // An open distance editor must never survive a structural shift bound to a
  // different stage: with an index-based FlatList key React would reuse the row
  // instance and the stale draft would commit onto the stage that slid into that
  // position. Reproduce the shift and assert the editor is torn down (#1044).
  it('tears down an open distance editor when a stage is inserted before it', () => {
    const pA = { lat: 48.0, lon: 2.0, ele: 0 };
    const pB = { lat: 48.5, lon: 2.5, ele: 0 };
    const pC = { lat: 49.0, lon: 3.0, ele: 0 };
    const contiguous = (dayNumber: number, start: typeof pA, end: typeof pA) => ({
      ...stage(dayNumber),
      startPoint: start,
      endPoint: end,
    });
    act(() => {
      useTripStore.setState({
        stages: [contiguous(1, pA, pB), contiguous(2, pB, pC)],
      });
    });
    const tree = render(<RoadbookView id="t1" />);

    // Open the distance editor on the second stage (day 2) and stage a value.
    const editX = i18n.t('trip.edit.editDistanceA11y', { day: 2 });
    press(tree, editX);
    const input = tree.root.findByType(TextInput);
    act(() => input.props.onChangeText('999'));
    expect(tree.root.findAllByType(TextInput)).toHaveLength(1);

    // A manual stage inserted before it (placeholder spans the pB boundary)
    // slides the edited stage down a row.
    act(() =>
      useTripStore.getState().insertStageOptimistic(0, contiguous(0, pB, pB)),
    );

    expect(useTripStore.getState().stages).toHaveLength(3);
    // The stale editor is gone: no mounted distance input remains, so '999'
    // can no longer be committed onto the wrong stage.
    expect(tree.root.findAllByType(TextInput)).toHaveLength(0);
  });
});
