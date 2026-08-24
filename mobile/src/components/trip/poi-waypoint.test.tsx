/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import { EMPTY_RESUPPLY } from '@btp/core';
import type { ReactElement } from 'react';
import { Alert, Text } from 'react-native';
import * as SecureStore from 'expo-secure-store';
import type { StageData } from '@btp/core';
import i18n from '../../i18n';
import { useTripStore } from '../../store/trip-store';
import { useOfflineStore } from '../../store/offline-store';
import { useMapPrefs } from '../../store/map-prefs';
import { poiFromPressFeatures } from '../map/map-utils';
import { PoiWaypointPopover } from './PoiWaypointPopover';

jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn().mockResolvedValue(null),
  setItemAsync: jest.fn().mockResolvedValue(undefined),
}));

// Native map stubbed as plain host nodes so the props TripMap feeds each source
// (incl. the markers source's onPress) are recorded on the tree.
jest.mock('@maplibre/maplibre-react-native', () => {
  const React = require('react');
  const make = (name: string) => {
    const C = ({ children, ...rest }: { children?: unknown }) =>
      React.createElement(name, rest, children);
    C.displayName = name;
    return C;
  };
  return {
    Map: make('Map'),
    Camera: make('Camera'),
    GeoJSONSource: make('GeoJSONSource'),
    Layer: make('Layer'),
  };
});

jest.mock('../../api/trips', () => ({
  fetchStageDetail: jest.fn().mockResolvedValue(undefined),
  addPoiWaypoint: jest.fn(),
  updateStageDistance: jest.fn(),
  setStageAccommodation: jest.fn(),
  scanAccommodations: jest.fn(),
  addManualAccommodation: jest.fn(),
  applyBatchRecompute: jest.fn(),
  analyzeTrip: jest.fn(),
  updateTripConfig: jest.fn(),
  createStage: jest.fn(),
  deleteStage: jest.fn(),
  insertRestDay: jest.fn(),
  moveStage: jest.fn(),
  duplicateTrip: jest.fn(),
  deleteTrip: jest.fn(),
}));

import { addPoiWaypoint } from '../../api/trips';
import { TripMap } from '../TripMap';
import { StageDetailView } from './StageDetailView';

const mock = <T extends (...args: never[]) => unknown>(fn: T) =>
  fn as unknown as jest.MockedFunction<T>;

function render(element: ReactElement): any {
  let out: any;
  act(() => {
    out = TestRenderer.create(element);
  });
  return out;
}

function buttonByLabel(root: any, text: string): any {
  return root.findAll(
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

const poiFeature = (
  name: string,
  lon: number,
  lat: number,
): GeoJSON.Feature => ({
  type: 'Feature',
  properties: { kind: 'poi', name },
  geometry: { type: 'Point', coordinates: [lon, lat] },
});

describe('poiFromPressFeatures', () => {
  it('extracts the tapped POI (name + coords)', () => {
    expect(poiFromPressFeatures([poiFeature('Fontaine', 3, 45)])).toEqual({
      kind: 'poi',
      name: 'Fontaine',
      lon: 3,
      lat: 45,
    });
  });

  it('ignores non-POI markers (waypoint / accommodation) and empty presses', () => {
    const waypoint: GeoJSON.Feature = {
      type: 'Feature',
      properties: { kind: 'waypoint', name: 'Lyon' },
      geometry: { type: 'Point', coordinates: [4, 45] },
    };
    expect(poiFromPressFeatures([waypoint])).toBeNull();
    expect(poiFromPressFeatures([])).toBeNull();
    expect(poiFromPressFeatures(undefined)).toBeNull();
  });
});

describe('PoiWaypointPopover', () => {
  beforeAll(async () => {
    await i18n.changeLanguage('fr');
  });

  const poi = { kind: 'poi' as const, name: 'Fontaine', lon: 3, lat: 45 };

  it('adds and closes via its actions', () => {
    const onAdd = jest.fn();
    const onClose = jest.fn();
    const tree = render(
      <PoiWaypointPopover poi={poi} onAdd={onAdd} onClose={onClose} />,
    );

    act(() => buttonByLabel(tree.root, i18n.t('trip.poiWaypoint.add')).props.onPress());
    expect(onAdd).toHaveBeenCalledTimes(1);

    const close = tree.root.find(
      (n: any) =>
        n.props.accessibilityLabel === i18n.t('trip.poiWaypoint.close') &&
        typeof n.props.onPress === 'function',
    );
    act(() => close.props.onPress());
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('disables the add action when gated', () => {
    const tree = render(
      <PoiWaypointPopover poi={poi} onAdd={jest.fn()} onClose={jest.fn()} disabled />,
    );
    const add = buttonByLabel(tree.root, i18n.t('trip.poiWaypoint.add'));
    expect(add.props.accessibilityState.disabled).toBe(true);
  });
});

describe('TripMap POI selection wiring', () => {
  beforeEach(() => {
    act(() => {
      useMapPrefs.setState({ base: 'map', hydrated: true });
      useOfflineStore.setState({ isOnline: true });
    });
  });

  const markers = [{ kind: 'poi' as const, lon: 3, lat: 45, name: 'Fontaine' }];
  const segs = [{ color: 'hsl(25, 72%, 48%)', coordinates: [[2, 48], [3, 49]] as [number, number][] }];

  it('calls onSelectPoi with the tapped POI when the markers source is pressed', () => {
    const onSelectPoi = jest.fn();
    const out = render(
      <TripMap stageSegments={segs} markers={markers} onSelectPoi={onSelectPoi} />,
    );
    const source = out.root.find(
      (n: any) => n.type === 'GeoJSONSource' && n.props.id === 'markers',
    );
    expect(typeof source.props.onPress).toBe('function');
    act(() =>
      source.props.onPress({ nativeEvent: { features: [poiFeature('Fontaine', 3, 45)] } }),
    );
    expect(onSelectPoi).toHaveBeenCalledWith({
      kind: 'poi',
      name: 'Fontaine',
      lon: 3,
      lat: 45,
    });
  });

  it('leaves the markers source non-interactive when onSelectPoi is omitted', () => {
    const out = render(<TripMap stageSegments={segs} markers={markers} />);
    const source = out.root.find(
      (n: any) => n.type === 'GeoJSONSource' && n.props.id === 'markers',
    );
    expect(source.props.onPress).toBeUndefined();
  });
});

describe('StageDetailView add-POI-waypoint wiring (#1179)', () => {
  let alertSpy: jest.SpyInstance;

  function stageWithGeometry(): StageData {
    return {
      dayNumber: 1,
      distance: 50,
      elevation: 800,
      elevationLoss: 600,
      startPoint: { lat: 48, lon: 2, ele: 0 },
      endPoint: { lat: 49, lon: 3, ele: 0 },
      geometry: [
        { lat: 48, lon: 2, ele: 0 },
        { lat: 49, lon: 3, ele: 0 },
      ],
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

  beforeAll(async () => {
    await i18n.changeLanguage('fr');
  });

  beforeEach(() => {
    jest.clearAllMocks();
    (SecureStore.setItemAsync as jest.Mock).mockClear();
    alertSpy = jest.spyOn(Alert, 'alert').mockImplementation(() => {});
    act(() => {
      useTripStore.getState().reset();
      useMapPrefs.setState({ base: 'map', hydrated: true });
      useOfflineStore.setState({ isOnline: true, apiReachable: true });
      useTripStore.setState({
        tripId: 't1',
        stages: [stageWithGeometry()],
        isLocked: false,
        outOfZone: false,
        startDate: null,
        endDate: null,
        loading: false,
      });
    });
  });

  afterEach(() => alertSpy.mockRestore());

  function markersSource(tree: any): any {
    return tree.root.find(
      (n: any) => n.type === 'GeoJSONSource' && n.props.id === 'markers',
    );
  }

  it('opens the popover on a POI tap and reroutes via runAddPoiWaypoint', async () => {
    mock(addPoiWaypoint).mockResolvedValue({ ok: true, status: 202 });
    const tree = render(<StageDetailView initialIndex={0} />);

    // Tap a POI marker on the stage map.
    act(() =>
      markersSource(tree).props.onPress({
        nativeEvent: { features: [poiFeature('Fontaine', 3.1, 45.2)] },
      }),
    );
    // Confirm from the popover.
    act(() =>
      buttonByLabel(tree.root, i18n.t('trip.poiWaypoint.add')).props.onPress(),
    );
    await act(async () => {});

    // index is String()-ified in the API layer; the runner passed stage 0 + coords.
    expect(addPoiWaypoint).toHaveBeenCalledWith('t1', 0, 45.2, 3.1);
  });

  it('offers no POI affordance in degraded (offline) mode', () => {
    act(() => useOfflineStore.setState({ isOnline: false }));
    const tree = render(<StageDetailView initialIndex={0} />);
    // Read-only: the markers source carries no onPress, so a tap is inert and no
    // reroute can be dispatched.
    expect(markersSource(tree).props.onPress).toBeUndefined();
    expect(addPoiWaypoint).not.toHaveBeenCalled();
  });

  it('offers no POI affordance on a rest day (no route to reroute) (#1179 review)', () => {
    // A rest day still renders its own point on this map, but has no route to
    // reroute — canAddWaypoint must exclude it, leaving the markers inert.
    act(() =>
      useTripStore.setState({ stages: [{ ...stageWithGeometry(), isRestDay: true }] }),
    );
    const tree = render(<StageDetailView initialIndex={0} />);
    expect(markersSource(tree).props.onPress).toBeUndefined();
    expect(addPoiWaypoint).not.toHaveBeenCalled();
  });
});
