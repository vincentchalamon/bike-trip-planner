/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import { EMPTY_RESUPPLY } from '@btp/core';
import type { ReactElement } from 'react';
import type { StageData } from '@btp/core';
import {
  ShareInfographic,
  projectRoute,
  CARD_WIDTH,
  CARD_HEIGHT,
} from './ShareInfographic';
import { buildStageLines } from '../map/map-utils';

function render(element: ReactElement): any {
  let tree: any;
  act(() => {
    tree = TestRenderer.create(element);
  });
  return tree;
}

// A decimated stage caps at ~1.5k points; a multi-day trip flattens several
// thousand. Build a big, valid (lat/lon in range, monotonic) geometry so the
// bounds maths runs on a realistically large array.
function bigStage(
  overrides: Partial<StageData> = {},
  points = 4000,
  lonBase = 4,
): StageData {
  const geometry = Array.from({ length: points }, (_, i) => ({
    lat: 45 + (i / points) * 0.5,
    lon: lonBase + (i / points) * 0.5,
    ele: 100 + Math.sin(i / 50) * 400,
  }));
  const first = geometry[0]!;
  const last = geometry[geometry.length - 1]!;
  const dayNumber = overrides.dayNumber ?? 1;
  return {
    id: `stage-${dayNumber}`,
    dayNumber,
    distance: 80,
    elevation: 900,
    elevationLoss: 850,
    startPoint: first,
    endPoint: last,
    geometry,
    label: null,
    startLabel: null,
    endLabel: null,
    weather: null,
    alerts: [],
    resupply: EMPTY_RESUPPLY,
    accommodations: [],
    selectedAccommodation: null,
    accommodationSearchRadiusKm: 5,
    isRestDay: false,
    supplyTimeline: [],
    events: [],
    ...overrides,
  };
}

const labels = {
  distance: 'Distance',
  elevation: 'Dénivelé',
  dates: 'Dates',
  budget: 'Budget',
  difficulty: { label: 'Difficulté', easy: 'Facile', medium: 'Modéré', hard: 'Difficile' },
  powered: 'Bike Trip Planner',
};

describe('projectRoute (#1048)', () => {
  it('projects a large multi-day route within the map box without crashing', () => {
    const stages = [
      bigStage({ dayNumber: 1 }, 4000, 4),
      bigStage({ dayNumber: 2 }, 4000, 4.5),
      bigStage({ dayNumber: 3 }, 4000, 5),
    ];
    const w = CARD_WIDTH;
    const h = 220;
    let route!: ReturnType<typeof projectRoute>;
    expect(() => {
      route = projectRoute(stages, w, h);
    }).not.toThrow();

    expect(route.polylines).toHaveLength(3);
    for (const pt of [route.start, route.end]) {
      expect(pt).not.toBeNull();
      expect(pt!.x).toBeGreaterThanOrEqual(0);
      expect(pt!.x).toBeLessThanOrEqual(w);
      expect(pt!.y).toBeGreaterThanOrEqual(0);
      expect(pt!.y).toBeLessThanOrEqual(h);
    }
  });
});

describe('projectRoute stage colours', () => {
  // A rest day shifts the positions but not the day numbers: the share image
  // used to colour by position from its own palette, the map by day number.
  it('paints each stage in the colour the map draws it in', () => {
    const stages = [
      bigStage({ dayNumber: 1 }, 10, 4),
      bigStage({ dayNumber: 2, isRestDay: true }, 10, 4.5),
      bigStage({ dayNumber: 3 }, 10, 5),
    ];

    const onImage = projectRoute(stages, CARD_WIDTH, 220).polylines.map((p) => p.color);
    const onMap = buildStageLines(stages).map((line) => line.color);

    expect(onImage).toEqual(onMap);
  });
});

describe('ShareInfographic (#1048)', () => {
  it('renders a multi-day trip with several thousand points without crashing', () => {
    const stages = [
      bigStage({ dayNumber: 1 }, 4000, 4),
      bigStage({ dayNumber: 2 }, 4000, 4.5),
    ];
    let tree: any;
    expect(() => {
      tree = render(
        <ShareInfographic
          title="Traversée des Alpes"
          stages={stages}
          startDate="2026-06-01"
          endDate="2026-06-02"
          labels={labels}
        />,
      );
    }).not.toThrow();
    expect(tree.root.findByProps({ testID: 'share-infographic' })).toBeTruthy();
    expect(CARD_HEIGHT).toBeGreaterThan(0);
    act(() => tree.unmount());
  });
});
