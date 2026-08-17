/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import type { ReactElement } from 'react';
import * as SecureStore from 'expo-secure-store';
import '../i18n';
import { useMapPrefs } from '../store/map-prefs';
import { TripMap } from './TripMap';
import { computeBounds, POSITRON_STYLE_URL } from './map/map-utils';

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

beforeEach(() => {
  setItem.mockClear();
  // Pre-hydrated so the mount-time load() is a no-op (no async store write to
  // chase through act()); these tests drive the base explicitly via the toggle.
  act(() => {
    useMapPrefs.setState({ base: 'map', hydrated: true });
  });
});

describe('TripMap', () => {
  it('renders the empty state when there is no route', () => {
    const out = render(<TripMap coordinates={[]} />);
    expect(findAll(out.root, 'Map')).toHaveLength(0);
  });

  it('feeds the memoized Positron style, then the satellite style once toggled', () => {
    const out = render(<TripMap coordinates={coordsA} />);
    const style = () => findAll(out.root, 'Map')[0].props.mapStyle;
    expect(style()).toBe(POSITRON_STYLE_URL);

    act(() => {
      out.root
        .find(
          (n: any) =>
            n.props.accessibilityRole === 'button' &&
            typeof n.props.onPress === 'function',
        )
        .props.onPress();
    });

    // Satellite base swaps to the inline Esri raster style object...
    expect(style()).toMatchObject({ version: 8 });
    // ...and the choice is persisted.
    expect(setItem).toHaveBeenCalledWith('btp_map_base', 'satellite');
    // The route line switches to white for contrast over imagery.
    expect(byId(out.root, 'Layer', 'route-line')[0].props.paint['line-color']).toBe(
      '#ffffff',
    );
  });

  it('re-frames the camera reactively when coordinates change', () => {
    const out = render(<TripMap coordinates={coordsA} />);
    const camera = () => findAll(out.root, 'Camera')[0].props;
    expect(camera().bounds).toEqual(computeBounds(coordsA));

    // Same (non re-keyed) Camera instance, new coordinates -> new bounds prop.
    act(() => {
      out.update(<TripMap coordinates={coordsB} />);
    });
    expect(camera().bounds).toEqual(computeBounds(coordsB));
  });

  it('draws the highlighted segment only once it has at least two points', () => {
    const out = render(
      <TripMap coordinates={coordsA} highlightedSegment={[[2, 48]]} />,
    );
    expect(byId(out.root, 'GeoJSONSource', 'segment-highlight')).toHaveLength(0);

    act(() => {
      out.update(
        <TripMap
          coordinates={coordsA}
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
    const withoutMarkers = render(<TripMap coordinates={coordsA} />);
    expect(byId(withoutMarkers.root, 'Layer', 'markers-circle')).toHaveLength(0);

    const withMarkers = render(
      <TripMap
        coordinates={coordsA}
        markers={[{ kind: 'poi', lon: 2.5, lat: 48.5, name: 'Café' }]}
      />,
    );
    expect(byId(withMarkers.root, 'Layer', 'markers-circle')).toHaveLength(1);
  });

  it('colors the three marker kinds distinctly (poi/accommodation/waypoint)', () => {
    const withMarkers = render(
      <TripMap
        coordinates={coordsA}
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
