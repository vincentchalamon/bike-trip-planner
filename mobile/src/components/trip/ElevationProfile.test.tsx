/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import type { ReactElement } from 'react';
import type { StageData } from '@btp/core';
import '../../i18n';
import { ElevationProfile } from './ElevationProfile';

// Render react-native-svg as inert host-like components so the tree resolves
// under react-test-renderer without the native module.
jest.mock('react-native-svg', () => {
  const React = require('react');
  const make = (name: string) => (props: Record<string, unknown>) =>
    React.createElement(name, props, (props as { children?: unknown }).children);
  const Svg = make('Svg');
  return { __esModule: true, default: Svg, Svg, Path: make('Path'), Line: make('Line'), G: make('G') };
});

function render(element: ReactElement): any {
  let out: any;
  act(() => {
    out = TestRenderer.create(element);
  });
  return out;
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

const climbingStage = stage({
  geometry: [
    { lat: 48, lon: 2, ele: 100 },
    { lat: 48.01, lon: 2, ele: 200 },
    { lat: 48.02, lon: 2, ele: 150 },
  ],
});

describe('ElevationProfile', () => {
  it('renders nothing without at least two profile points', () => {
    const tree = render(
      <ElevationProfile stages={[stage()]} focusedStageIndex={null} onHover={jest.fn()} />,
    );
    expect(tree.toJSON()).toBeNull();
  });

  it('renders one area Path per active stage', () => {
    const tree = render(
      <ElevationProfile stages={[climbingStage]} focusedStageIndex={null} onHover={jest.fn()} />,
    );
    const paths = tree.root.findAllByType('Path' as never);
    expect(paths).toHaveLength(1);
    expect(typeof paths[0]!.props.d).toBe('string');
  });

  it('reports the hovered coord/stage on touch and clears on release', () => {
    const onHover = jest.fn();
    const tree = render(
      <ElevationProfile stages={[climbingStage]} focusedStageIndex={null} onHover={onHover} />,
    );
    const view = tree.root.findByProps({ testID: 'elevation-profile' });
    act(() => {
      view.props.onLayout({ nativeEvent: { layout: { width: 800 } } });
    });
    act(() => {
      // Far right of the profile → the last geometry coord of the stage.
      view.props.onResponderGrant({ nativeEvent: { locationX: 800 } });
    });
    expect(onHover).toHaveBeenCalledWith(2, 0);
    // A crosshair Line appears while hovering.
    expect(tree.root.findAllByType('Line' as never)).toHaveLength(1);

    act(() => {
      view.props.onResponderRelease();
    });
    expect(onHover).toHaveBeenLastCalledWith(null, null);
    expect(tree.root.findAllByType('Line' as never)).toHaveLength(0);
  });
});
