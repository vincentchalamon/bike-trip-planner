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

import { applyBatchRecompute } from '../../api/trips';
import { RoadbookView } from './RoadbookView';
import type { Modification } from '../../store/trip-store';

const mock = <T extends (...args: never[]) => unknown>(fn: T) =>
  fn as unknown as jest.MockedFunction<T>;

function stage(dayNumber: number): StageData {
  return {
    dayNumber,
    distance: 50,
    elevation: 100,
    elevationLoss: 0,
    startPoint: { lat: 0, lon: 0, ele: 0 },
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

const MODS: Modification[] = [
  { stageIndex: 0, type: 'distance', label: 'Jour 1 · distance' },
  { stageIndex: null, type: 'dates', label: 'Dates' },
];

function render(element: ReactElement): any {
  let out: any;
  act(() => {
    out = TestRenderer.create(element);
  });
  return out;
}

// Find the Button (role=button) whose rendered label matches `text`.
function buttonByLabel(tree: any, text: string): any {
  return tree.root.findAll(
    (n: any) =>
      n.props.accessibilityRole === 'button' &&
      typeof n.props.onPress === 'function' &&
      n
        .findAllByType(Text)
        .some((txt: any) =>
          (Array.isArray(txt.props.children)
            ? txt.props.children
            : [txt.props.children]
          ).includes(text),
        ),
  )[0];
}

const applyLabel = () => i18n.t('trip.modificationQueue.applyAll');
const cancelLabel = () => i18n.t('trip.modificationQueue.cancel');

describe('RoadbookView modification queue wiring (#1179)', () => {
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
        startDate: null,
        endDate: null,
        loading: false,
        pendingModifications: MODS,
      });
    });
  });

  afterEach(() => alertSpy.mockRestore());

  it('hides the panel when the queue is empty', () => {
    act(() => useTripStore.setState({ pendingModifications: [] }));
    const tree = render(<RoadbookView id="t1" />);
    expect(
      tree.root.findAll(
        (n: any) =>
          n.props.accessibilityLabel === i18n.t('trip.modificationQueue.panelA11y'),
      ),
    ).toHaveLength(0);
  });

  it('lists the pending modifications with a pluralized count', () => {
    const tree = render(<RoadbookView id="t1" />);
    const panel = tree.root.find(
      (n: any) =>
        n.props.accessibilityLabel === i18n.t('trip.modificationQueue.panelA11y'),
    );
    const strings = panel
      .findAllByType(Text)
      .flatMap((n: any) =>
        Array.isArray(n.props.children) ? n.props.children : [n.props.children],
      );
    expect(strings).toContain(
      i18n.t('trip.modificationQueue.title', { count: 2 }),
    );
    expect(strings).toContain('Jour 1 · distance');
    expect(strings).toContain('Dates');
  });

  it('applies the whole queue in one recompute (runApplyBatch) and clears it', async () => {
    mock(applyBatchRecompute).mockResolvedValue({ ok: true, status: 202 });
    const tree = render(<RoadbookView id="t1" />);

    act(() => buttonByLabel(tree, applyLabel()).props.onPress());
    await act(async () => {});

    expect(applyBatchRecompute).toHaveBeenCalledWith('t1', [
      { stageIndex: 0, type: 'distance', label: 'Jour 1 · distance' },
      { stageIndex: null, type: 'dates', label: 'Dates' },
    ]);
    // A successful batch clears the queue.
    expect(useTripStore.getState().pendingModifications).toHaveLength(0);
  });

  it('clears the queue without a recompute on cancel', () => {
    const tree = render(<RoadbookView id="t1" />);
    act(() => buttonByLabel(tree, cancelLabel()).props.onPress());
    expect(applyBatchRecompute).not.toHaveBeenCalled();
    expect(useTripStore.getState().pendingModifications).toHaveLength(0);
  });

  it('refuses the batch in degraded (offline) mode: apply disabled, no recompute', async () => {
    act(() => useOfflineStore.setState({ isOnline: false }));
    const tree = render(<RoadbookView id="t1" />);

    const apply = buttonByLabel(tree, applyLabel());
    // UI-level: the action reports itself disabled in read-only / degraded mode.
    expect(apply.props.accessibilityState.disabled).toBe(true);

    // Gate-level: even if the press slips through, the transversal gate (#1166)
    // refuses the write — no recompute leaves the device, the failure is surfaced.
    act(() => apply.props.onPress());
    await act(async () => {});
    expect(applyBatchRecompute).not.toHaveBeenCalled();
    expect(alertSpy).toHaveBeenCalledWith(
      i18n.t('trip.edit.failedTitle'),
      i18n.t('trip.edit.reason.offline'),
    );
  });
});
