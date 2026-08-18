/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import type { ReactElement } from 'react';
import type { StageData } from '@btp/core';
import i18n from '../../i18n';
import { useTripStore } from '../../store/trip-store';

const mockPush = jest.fn();
jest.mock('expo-router', () => ({ useRouter: () => ({ push: mockPush }) }));

import { RoadbookView } from './RoadbookView';

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
});
