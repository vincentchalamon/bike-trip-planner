/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import type { ReactElement } from 'react';
import { Text } from 'react-native';
import type { StageData } from '@btp/core';
import i18n from '../../i18n';
import { fr } from '../../i18n/resources/fr';
import { StageDetailView } from './StageDetailView';
import { useTripStore } from '../../store/trip-store';
import {
  activeStageIndex,
  clampIndex,
  hasNextStage,
  hasPrevStage,
  ownsTripLive,
  parseStageIndex,
  stageGeometryCoords,
  stageStats,
  surfaceShares,
} from './stage-detail';

// maplibre is native; stub it so StageDetailView's TripMap import resolves under
// react-test-renderer (react-native-svg is left real — the lucide icons render
// through it).
jest.mock('@maplibre/maplibre-react-native', () => ({
  Camera: () => null,
  GeoJSONSource: ({ children }: { children?: unknown }) => children ?? null,
  Layer: () => null,
  Map: ({ children }: { children?: unknown }) => children ?? null,
}));

function textOf(node: any): string[] {
  const kids = Array.isArray(node.props.children) ? node.props.children : [node.props.children];
  return kids.filter((c: unknown): c is string => typeof c === 'string');
}

function texts(node: any): string[] {
  return node.root.findAllByType(Text).flatMap(textOf);
}

// Locate a nav Button (role="button") by its visible label — robust to the order
// findAllByProps returns nodes in.
function navButton(tree: any, label: string): any {
  return tree.root
    .findAllByProps({ accessibilityRole: 'button' })
    .find((b: any) => b.findAllByType(Text).some((tx: any) => textOf(tx).includes(label)));
}

let lastTree: any;
function render(element: ReactElement): any {
  act(() => {
    lastTree = TestRenderer.create(element);
  });
  return lastTree;
}

function stage(overrides: Partial<StageData> = {}): StageData {
  const point = { lat: 0, lon: 0, ele: 0 };
  return {
    dayNumber: 1,
    distance: 50,
    elevation: 800,
    elevationLoss: 600,
    startPoint: point,
    endPoint: point,
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
    ...overrides,
  };
}

beforeAll(async () => {
  await i18n.changeLanguage('fr');
});

afterEach(() => {
  act(() => {
    lastTree?.unmount();
    lastTree = undefined;
    useTripStore.getState().reset();
  });
});

describe('stage-detail helpers', () => {
  it('parses the route index param, defaulting to 0 on garbage', () => {
    expect(parseStageIndex('3')).toBe(3);
    expect(parseStageIndex(undefined)).toBe(0);
    expect(parseStageIndex('-2')).toBe(0);
    expect(parseStageIndex('x')).toBe(0);
  });

  it('clamps an index into the stage range', () => {
    expect(clampIndex(5, 3)).toBe(2);
    expect(clampIndex(-1, 3)).toBe(0);
    expect(clampIndex(0, 0)).toBe(0);
  });

  it('bounds prev/next at the extremities', () => {
    expect(hasPrevStage(0)).toBe(false);
    expect(hasPrevStage(1)).toBe(true);
    expect(hasNextStage(2, 3)).toBe(false);
    expect(hasNextStage(1, 3)).toBe(true);
  });

  it('rounds per-stage stats', () => {
    expect(stageStats(stage({ distance: 50.4, elevation: 812.6, elevationLoss: 599.5 }))).toEqual({
      distanceKm: 50,
      elevationGain: 813,
      elevationLoss: 600,
    });
  });

  it('maps an absolute index to the rest-day-filtered active index', () => {
    const stages = [
      stage({ isRestDay: false }),
      stage({ isRestDay: true }),
      stage({ isRestDay: false }),
    ];
    expect(activeStageIndex(stages, 0)).toBe(0);
    expect(activeStageIndex(stages, 1)).toBeNull(); // rest day
    expect(activeStageIndex(stages, 2)).toBe(1); // skips the rest day
    expect(activeStageIndex(stages, 9)).toBeNull(); // out of bounds
  });

  it('owns the live store only on a deep-link entry (different tripId)', () => {
    expect(ownsTripLive('42', '42')).toBe(false); // roadbook already live
    expect(ownsTripLive(null, '42')).toBe(true); // deep link, store empty
    expect(ownsTripLive('7', '42')).toBe(true); // live for another trip
  });

  it('projects geometry to [lon, lat] pairs', () => {
    const s = stage({
      geometry: [
        { lat: 45, lon: 4, ele: 100 },
        { lat: 46, lon: 5, ele: 200 },
      ],
    });
    expect(stageGeometryCoords(s)).toEqual([
      [4, 45],
      [5, 46],
    ]);
  });

  it('computes surface shares as rounded percentages, largest first', () => {
    const s = stage({
      surfaceBreakdown: [
        { surface: 'gravel', lengthMeters: 2000 },
        { surface: 'paved', lengthMeters: 8000 },
      ],
    });
    expect(surfaceShares(s)).toEqual([
      { surface: 'paved', percent: 80 },
      { surface: 'gravel', percent: 20 },
    ]);
    expect(surfaceShares(stage())).toEqual([]);
  });
});

describe('StageDetailView', () => {
  it('renders the stage stats, labels and position', () => {
    useTripStore.setState({
      tripId: 't1',
      stages: [stage({ startLabel: 'Paris', endLabel: 'Lyon' }), stage({ dayNumber: 2 })],
      startDate: null,
      loading: false,
    });
    const t = texts(render(<StageDetailView id="t1" initialIndex={0} />)).join(' ');
    expect(t).toContain('Paris');
    expect(t).toContain('Lyon');
    expect(t).toContain('50 km');
    expect(t).toContain('800 m'); // D+
    expect(t).toContain('600 m'); // D-
    expect(t).toContain('Étape 1 / 2');
  });

  it('advances to the next stage and bounds prev/next at the extremities', () => {
    useTripStore.setState({
      tripId: 't1',
      stages: [
        stage({ dayNumber: 1, startLabel: 'A', endLabel: 'B' }),
        stage({ dayNumber: 2, startLabel: 'B', endLabel: 'C' }),
      ],
      startDate: null,
      loading: false,
    });
    const tree = render(<StageDetailView id="t1" initialIndex={0} />);
    const prev = () => navButton(tree, fr.trip.stageDetail.prev);
    const next = () => navButton(tree, fr.trip.stageDetail.next);

    // At the first stage: prev disabled, next enabled.
    expect(prev().props.accessibilityState.disabled).toBe(true);
    expect(next().props.accessibilityState.disabled).toBe(false);

    act(() => next().props.onPress());

    expect(texts(tree).join(' ')).toContain('Étape 2 / 2');
    // Now at the last stage: prev enabled, next disabled.
    expect(prev().props.accessibilityState.disabled).toBe(false);
    expect(next().props.accessibilityState.disabled).toBe(true);
  });

  it('shows a placeholder instead of the whole-trip profile on a rest day (#1039)', () => {
    useTripStore.setState({
      tripId: 't1',
      stages: [
        stage({ dayNumber: 1, isRestDay: false }),
        stage({ dayNumber: 2, isRestDay: true }),
      ],
      startDate: null,
      loading: false,
    });
    const t = texts(render(<StageDetailView id="t1" initialIndex={1} />));
    expect(t).toContain(fr.trip.stageDetail.restNoProfile);
  });

  it('renders the elevation profile on a riding day, not the rest placeholder (#1039)', () => {
    useTripStore.setState({
      tripId: 't1',
      stages: [stage({ dayNumber: 1, isRestDay: false })],
      startDate: null,
      loading: false,
    });
    const t = texts(render(<StageDetailView id="t1" initialIndex={0} />));
    expect(t).not.toContain(fr.trip.stageDetail.restNoProfile);
  });

  it('shows the not-found placeholder for an out-of-range stage', () => {
    useTripStore.setState({ tripId: 't1', stages: [], loading: false });
    const t = texts(render(<StageDetailView id="t1" initialIndex={0} />));
    expect(t).toContain(fr.trip.stageDetail.notFound);
  });
});
