/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import { EMPTY_RESUPPLY } from '@btp/core';
import type { ReactElement } from 'react';
import { Alert, Text } from 'react-native';
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

import { createStage, insertRestDay } from '../../api/trips';
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
    resupply: EMPTY_RESUPPLY,
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
const addStageA11y = () => i18n.t('trip.edit.addStageA11y', { day: 1 });

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
      useOfflineStore.setState({ isOnline: true, apiReachable: true });
      useTripStore.setState({
        stages: [stage(1), stage(2)],
        isLocked: false,
        outOfZone: false,
        // Undated trip → lifecycle is "unknown", so the roadbook stays editable
        // regardless of the (real) run date; the insertion rows are rendered.
        startDate: null,
        endDate: null,
        loading: false,
      });
    });
  });

  afterEach(() => alertSpy.mockRestore());

  it('renders an insertion row between stages but never after the last one', () => {
    const tree = render(<RoadbookView id="t1" />);
    const addStageCount = (day: number) =>
      tree.root.findAll(
        (n: any) =>
          n.props.accessibilityLabel === i18n.t('trip.edit.addStageA11y', { day }) &&
          typeof n.props.onPress === 'function',
      ).length;
    // Two stages → one insertion row (after day 1); nothing past the destination.
    expect(addStageCount(1)).toBe(1);
    expect(addStageCount(2)).toBe(0);
  });

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

  const bannerTexts = (tree: any): string[] =>
    tree.root
      .findAllByType(Text)
      .flatMap((n: any) =>
        Array.isArray(n.props.children) ? n.props.children : [n.props.children],
      )
      .filter((c: unknown): c is string => typeof c === 'string');

  const insertRows = (tree: any): unknown[] =>
    tree.root.findAll(
      (n: any) =>
        n.props.accessibilityLabel === restDayA11y() &&
        typeof n.props.onPress === 'function',
    );

  it('goes read-only while offline: hides edit affordances, shows the offline banner (#1166)', () => {
    useOfflineStore.setState({ isOnline: false });
    const tree = render(<RoadbookView id="t1" />);
    // Read-only: no insertion affordance to tap, so no mutation can be dispatched.
    expect(insertRows(tree)).toHaveLength(0);
    expect(insertRestDay).not.toHaveBeenCalled();
    // A discreet banner names the reason.
    expect(bannerTexts(tree)).toContain(i18n.t('trip.banners.offline'));
  });

  it('goes read-only when the API is unreachable while online (#1166)', () => {
    useOfflineStore.setState({ isOnline: true, apiReachable: false });
    const tree = render(<RoadbookView id="t1" />);
    expect(insertRows(tree)).toHaveLength(0);
    expect(insertRestDay).not.toHaveBeenCalled();
    expect(bannerTexts(tree)).toContain(i18n.t('trip.banners.apiUnavailable'));
  });

  it('commits an inline add-stage to the API with the boundary payload', async () => {
    mock(createStage).mockResolvedValue({ ok: true, status: 202 });
    const tree = render(<RoadbookView id="t1" />);

    press(tree, addStageA11y());

    await act(async () => {});
    // runAddStage splices at afterIndex+1 and routes prev.endPoint → next.startPoint.
    expect(createStage).toHaveBeenCalledWith('t1', {
      position: 1,
      startPoint: { lat: 1, lon: 1, ele: 0 },
      endPoint: { lat: 0, lon: 0, ele: 0 },
    });
  });

  it('guards a double-tap on ＋étape: two rapid presses fire a single createStage', async () => {
    mock(createStage).mockResolvedValue({ ok: true, status: 202 });
    const tree = render(<RoadbookView id="t1" />);

    // Capture the chip once and tap it twice within the same tick, before any
    // re-render can disable it — the in-flight ref must swallow the second tap.
    const chip = tree.root.find(
      (n: any) =>
        n.props.accessibilityLabel === addStageA11y() &&
        typeof n.props.onPress === 'function',
    );
    act(() => {
      chip.props.onPress();
      chip.props.onPress();
    });

    await act(async () => {});
    expect(createStage).toHaveBeenCalledTimes(1);
  });

});
