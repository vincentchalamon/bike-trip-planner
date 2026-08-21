/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import type { ReactElement } from 'react';
import * as SecureStore from 'expo-secure-store';
import '../i18n';
import { useMapPrefs } from '../store/map-prefs';
import { useOfflineStore } from '../store/offline-store';
import { TripMap } from './TripMap';
import { computeBounds, POSITRON_STYLE_URL, type StageLine } from './map/map-utils';

jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn().mockResolvedValue(null),
  setItemAsync: jest.fn().mockResolvedValue(undefined),
}));

// Render the native map components as plain host nodes so the tree records the
// props TripMap feeds them (mapStyle, camera stop, layer paint, source ids).
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

const setItem = SecureStore.setItemAsync as jest.Mock;

function render(element: ReactElement): any {
  let out: any;
  act(() => {
    out = TestRenderer.create(element);
  });
  return out;
}

function findAll(root: any, type: string): any[] {
  return root.findAll((n: any) => n.type === type);
}

function byId(root: any, type: string, id: string): any[] {
  return findAll(root, type).filter((n: any) => n.props.id === id);
}

const coordsA: [number, number][] = [
  [2, 48],
  [3, 49],
];
const coordsB: [number, number][] = [
  [5, 44],
  [6, 45],
];
const segsA: StageLine[] = [{ color: 'hsl(25.0, 72%, 48%)', coordinates: coordsA }];
const segsB: StageLine[] = [{ color: 'hsl(25.0, 72%, 48%)', coordinates: coordsB }];

beforeEach(() => {
  setItem.mockClear();
  // Pre-hydrated so the mount-time load() is a no-op (no async store write to
  // chase through act()); these tests drive the base explicitly via setBase.
  act(() => {
    useMapPrefs.setState({ base: 'map', hydrated: true });
  });
  // Reset so an offline flip in one test never leaks into the next.
  act(() => {
    useOfflineStore.setState({ isOnline: true });
  });
});

describe('TripMap', () => {
  it('renders the empty state when there is no route', () => {
    const out = render(<TripMap stageSegments={[]} />);
    expect(findAll(out.root, 'Map')).toHaveLength(0);
  });

  it('renders (framed on the markers) when a stage has markers but no drawable line', () => {
    // Rest-day case (#1142): the stage carries a single point, so `stageSegments`
    // is empty (no line to draw) but its location marker remains. The map must
    // still render and frame on the marker instead of falling through to the
    // empty state.
    const out = render(
      <TripMap
        stageSegments={[]}
        markers={[{ kind: 'waypoint', lon: 2.5, lat: 48.5, name: 'Repos' }]}
      />,
    );
    expect(findAll(out.root, 'Map')).toHaveLength(1);
    expect(findAll(out.root, 'Camera')[0].props.bounds).toEqual(
      computeBounds([[2.5, 48.5]]),
    );
  });

  it('feeds the memoized Positron style, then the satellite style once toggled', () => {
    const out = render(<TripMap stageSegments={segsA} />);
    const style = () => findAll(out.root, 'Map')[0].props.mapStyle;
    expect(style()).toBe(POSITRON_STYLE_URL);

    act(() => {
      // Tap the "Satellite" segment of the layers pill (multiple buttons now
      // exist: two layer segments + two zoom controls).
      out.root
        .find(
          (n: any) =>
            n.props.accessibilityRole === 'button' &&
            n.props.accessibilityLabel === 'Satellite' &&
            typeof n.props.onPress === 'function',
        )
        .props.onPress();
    });

    // Satellite base swaps to the inline Esri raster style object...
    expect(style()).toMatchObject({ version: 8 });
    // ...and the choice is persisted.
    expect(setItem).toHaveBeenCalledWith('btp_map_base', 'satellite');
    // The route keeps its data-driven per-stage color on satellite too (stages
    // stay distinguishable over imagery, like the web map).
    expect(byId(out.root, 'Layer', 'route-line')[0].props.paint['line-color']).toEqual([
      'get',
      'color',
    ]);
  });

  it('degrades to the offline style when useOfflineStore flips offline, then restores online', () => {
    const out = render(<TripMap stageSegments={segsA} />);
    const style = () => findAll(out.root, 'Map')[0].props.mapStyle;
    expect(style()).toBe(POSITRON_STYLE_URL);

    act(() => {
      useOfflineStore.setState({ isOnline: false });
    });
    // Tile-less offline style: no network sources, just a flat background layer.
    expect(style()).toMatchObject({ sources: {}, layers: [{ type: 'background' }] });

    act(() => {
      useOfflineStore.setState({ isOnline: true });
    });
    expect(style()).toBe(POSITRON_STYLE_URL);
  });

  it('hides the layer toggle while offline (no tiles to switch)', () => {
    // Offline, the map is stuck on the tile-less background style regardless of
    // `base` (see the test above) — the pill would look interactive but do
    // nothing, so it must not render at all while offline.
    const out = render(<TripMap stageSegments={segsA} />);
    const findToggle = () =>
      out.root.findAll(
        (n: any) =>
          n.props.accessibilityRole === 'button' &&
          n.props.accessibilityLabel === 'Satellite' &&
          typeof n.props.onPress === 'function',
      );
    expect(findToggle()).toHaveLength(1);

    act(() => {
      useOfflineStore.setState({ isOnline: false });
    });
    expect(findToggle()).toHaveLength(0);

    act(() => {
      useOfflineStore.setState({ isOnline: true });
    });
    expect(findToggle()).toHaveLength(1);
  });

  it('draws one colored LineString feature per stage', () => {
    const segs: StageLine[] = [
      { color: 'hsl(25.0, 72%, 48%)', coordinates: coordsA },
      { color: 'hsl(162.5, 72%, 48%)', coordinates: coordsB },
    ];
    const out = render(<TripMap stageSegments={segs} />);
    const source = byId(out.root, 'GeoJSONSource', 'route')[0]!;
    const features = source.props.data.features as {
      properties: { color: string };
    }[];
    // One feature per stage, each carrying its own color, and the single line
    // layer paints them via ['get', 'color'].
    expect(features).toHaveLength(2);
    expect(new Set(features.map((f) => f.properties.color)).size).toBe(2);
    expect(byId(out.root, 'Layer', 'route-line')[0].props.paint['line-color']).toEqual([
      'get',
      'color',
    ]);
  });

  it('re-frames the camera reactively when coordinates change', () => {
    const out = render(<TripMap stageSegments={segsA} />);
    const camera = () => findAll(out.root, 'Camera')[0].props;
    expect(camera().bounds).toEqual(computeBounds(coordsA));

    // Same (non re-keyed) Camera instance, new coordinates -> new bounds prop.
    act(() => {
      out.update(<TripMap stageSegments={segsB} />);
    });
    expect(camera().bounds).toEqual(computeBounds(coordsB));
  });

  it('draws the highlighted segment only once it has at least two points', () => {
    const out = render(
      <TripMap stageSegments={segsA} highlightedSegment={[[2, 48]]} />,
    );
    expect(byId(out.root, 'GeoJSONSource', 'segment-highlight')).toHaveLength(0);

    act(() => {
      out.update(
        <TripMap
          stageSegments={segsA}
          highlightedSegment={[
            [2, 48],
            [3, 49],
          ]}
        />,
      );
    });
    expect(byId(out.root, 'GeoJSONSource', 'segment-highlight')).toHaveLength(1);
  });

  it('renders the markers layer only when markers are provided', () => {
    const withoutMarkers = render(<TripMap stageSegments={segsA} />);
    expect(byId(withoutMarkers.root, 'Layer', 'markers-circle')).toHaveLength(0);

    const withMarkers = render(
      <TripMap
        stageSegments={segsA}
        markers={[{ kind: 'poi', lon: 2.5, lat: 48.5, name: 'Café' }]}
      />,
    );
    expect(byId(withMarkers.root, 'Layer', 'markers-circle')).toHaveLength(1);
  });

  it('colors the three marker kinds distinctly (poi/accommodation/waypoint)', () => {
    const withMarkers = render(
      <TripMap
        stageSegments={segsA}
        markers={[{ kind: 'poi', lon: 2.5, lat: 48.5, name: 'Café' }]}
      />,
    );
    const layer = byId(withMarkers.root, 'Layer', 'markers-circle')[0]!;
    // ['match', ['get','kind'], 'poi', <poi>, 'accommodation', <acc>, <waypoint>]
    const match = layer.props.paint['circle-color'] as unknown[];
    const [poi, accommodation, waypoint] = [match[3], match[5], match[6]];
    expect(new Set([poi, accommodation, waypoint]).size).toBe(3);
  });
});
