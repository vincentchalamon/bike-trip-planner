/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import { EMPTY_RESUPPLY } from '@btp/core';
import type { ReactElement } from 'react';
import type { StageData } from '@btp/core';
import '../../i18n';
import { ElevationProfile, projectTouchToDistanceKm } from './ElevationProfile';

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
    resupply: EMPTY_RESUPPLY,
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

describe('projectTouchToDistanceKm (padding-aware touch mapping)', () => {
  // Container has 8px horizontal padding; onLayout reports the border-box width
  // (816 here → 800px content box that the Svg fills at width="100%").
  const WIDTH = 816;

  it('maps the left content edge to ~0 km, not into the profile', () => {
    // A touch at the left content edge (locationX = PAD_H) must land at distance
    // 0, not drift right. Without de-padding, svgX would be (8/816)*800 ≈ 7.84,
    // yielding a positive distance — this asserts the corrected mapping is ≤ 0.
    expect(projectTouchToDistanceKm(8, WIDTH, 100)).toBeLessThanOrEqual(0);
  });

  it('maps the right content edge to (near) the full distance', () => {
    expect(projectTouchToDistanceKm(WIDTH - 8, WIDTH, 100)).toBeGreaterThanOrEqual(99);
  });

  it('clamps touches inside the padding gutters to the content box', () => {
    expect(projectTouchToDistanceKm(0, WIDTH, 100)).toBeLessThanOrEqual(0);
    expect(projectTouchToDistanceKm(WIDTH, WIDTH, 100)).toBeGreaterThanOrEqual(99);
  });

  it('returns null before the container has been measured', () => {
    expect(projectTouchToDistanceKm(100, 0, 100)).toBeNull();
  });
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

  it('bubbles onHover only when the resolved point index changes', () => {
    const onHover = jest.fn();
    const tree = render(
      <ElevationProfile stages={[climbingStage]} focusedStageIndex={null} onHover={onHover} />,
    );
    const view = tree.root.findByProps({ testID: 'elevation-profile' });
    act(() => {
      view.props.onLayout({ nativeEvent: { layout: { width: 800 } } });
    });
    // Two moves landing on the same nearest coord → a single onHover call.
    act(() => {
      view.props.onResponderGrant({ nativeEvent: { locationX: 799 } });
    });
    act(() => {
      view.props.onResponderMove({ nativeEvent: { locationX: 800 } });
    });
    expect(onHover).toHaveBeenCalledTimes(1);
    expect(onHover).toHaveBeenLastCalledWith(2, 0);
    // A move resolving to a different coord fires again.
    act(() => {
      view.props.onResponderMove({ nativeEvent: { locationX: 0 } });
    });
    expect(onHover).toHaveBeenCalledTimes(2);
    expect(onHover).toHaveBeenLastCalledWith(0, 0);
  });
});
