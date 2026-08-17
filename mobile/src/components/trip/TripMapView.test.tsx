/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import type { ReactElement } from 'react';
import type { StageData } from '@btp/core';
import '../../i18n';
import { useTripStore } from '../../store/trip-store';
import { collectMarkers } from '../map/map-utils';

// Capture the props TripMap receives so we can assert the wired markers.
let lastTripMapProps: Record<string, unknown> | null = null;
jest.mock('../TripMap', () => {
  const React = require('react');
  return {
    TripMap: (props: Record<string, unknown>) => {
      lastTripMapProps = props;
      return React.createElement('TripMap', props);
    },
  };
});

import { TripMapView } from './TripMapView';

let current: ReturnType<typeof TestRenderer.create> | null = null;
function render(element: ReactElement): any {
  act(() => {
    current = TestRenderer.create(element);
  });
  return current;
}

function stage(overrides: Partial<StageData> = {}): StageData {
  const zero = { lat: 0, lon: 0, ele: 0 };
  return {
    dayNumber: 1,
    distance: 50,
    elevation: 100,
    elevationLoss: 0,
    startPoint: zero,
    endPoint: zero,
    geometry: [],
    label: null,
    startLabel: null,
    endLabel: null,
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

const routedStage = stage({
  geometry: [
    { lat: 48, lon: 2, ele: 100 },
    { lat: 48.01, lon: 2.01, ele: 200 },
  ],
  pois: [{ name: 'Café', category: 'amenity', lat: 48.01, lon: 2.01 }] as StageData['pois'],
});

describe('TripMapView', () => {
  beforeEach(() => {
    lastTripMapProps = null;
    act(() => {
      useTripStore.getState().reset();
      useTripStore.setState({ stages: [routedStage] });
    });
  });

  afterEach(() => {
    act(() => {
      current?.unmount();
    });
    current = null;
  });

  it('derives the markers from the store stages and forwards them to TripMap', () => {
    render(<TripMapView />);
    expect(lastTripMapProps).not.toBeNull();
    expect(lastTripMapProps!.markers).toEqual(collectMarkers([routedStage]));
  });
});
