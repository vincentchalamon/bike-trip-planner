/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import type { ReactElement } from 'react';
import { Text } from 'react-native';
import { Polyline } from 'react-native-svg';
import type { StageData } from '@btp/core';
import { EMPTY_RESUPPLY } from '@btp/core';
import i18n from '../../i18n';
import { buildStageLines, collectMarkers } from '../map/map-utils';
import { StaticRouteMap } from './StaticRouteMap';

function stage(over: Partial<StageData> = {}): StageData {
  const p = (lat: number, lon: number) => ({ lat, lon, ele: 0 });
  return {
    dayNumber: 1,
    distance: 50,
    elevation: 100,
    elevationLoss: 0,
    startPoint: p(48, 2),
    endPoint: p(48.1, 2.1),
    geometry: [p(48, 2), p(48.05, 2.05), p(48.1, 2.1)],
    label: null,
    startLabel: 'A',
    endLabel: 'B',
    weather: null,
    alerts: [],
    resupply: EMPTY_RESUPPLY,
    accommodations: [],
    selectedAccommodation: null,
    accommodationSearchRadiusKm: 10,
    isRestDay: false,
    supplyTimeline: [],
    events: [],
    ...over,
  };
}

beforeAll(async () => {
  await i18n.changeLanguage('fr');
});

function render(el: ReactElement): any {
  let tree: any;
  act(() => {
    tree = TestRenderer.create(el);
  });
  return tree;
}

describe('StaticRouteMap (#1168)', () => {
  it('draws one polyline per drawable stage and shows the offline note', () => {
    const stages = [stage(), stage({ dayNumber: 2 })];
    const tree = render(
      <StaticRouteMap
        stageSegments={buildStageLines(stages)}
        markers={collectMarkers(stages)}
      />,
    );
    expect(tree.root.findAllByType(Polyline)).toHaveLength(2);
    const texts = tree.root
      .findAllByType(Text)
      .flatMap((n: any) =>
        Array.isArray(n.props.children) ? n.props.children : [n.props.children],
      );
    expect(texts).toContain(i18n.t('trip.map.offlineStatic'));
  });

  it('renders the offline note even with no geometry (no crash)', () => {
    const tree = render(<StaticRouteMap stageSegments={[]} markers={[]} />);
    expect(tree.root.findAllByType(Polyline)).toHaveLength(0);
    const texts = tree.root
      .findAllByType(Text)
      .flatMap((n: any) =>
        Array.isArray(n.props.children) ? n.props.children : [n.props.children],
      );
    expect(texts).toContain(i18n.t('trip.map.offlineStatic'));
  });
});
