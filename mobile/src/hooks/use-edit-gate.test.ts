/// <reference types="jest" />
import { createElement } from 'react';
import TestRenderer, { act } from 'react-test-renderer';
import { useEditGate } from './use-edit-gate';
import { useTripStore } from '../store/trip-store';
import { useOfflineStore } from '../store/offline-store';

function gateOf(requiresRouting: boolean): ReturnType<typeof useEditGate> {
  let gate: ReturnType<typeof useEditGate> = null;
  function Probe() {
    gate = useEditGate(requiresRouting);
    return null;
  }
  let renderer!: ReturnType<typeof TestRenderer.create>;
  act(() => {
    renderer = TestRenderer.create(createElement(Probe));
  });
  act(() => renderer.unmount());
  return gate;
}

beforeEach(() => {
  useTripStore.getState().reset();
  useOfflineStore.setState({ isOnline: true, apiReachable: true });
});

describe('useEditGate', () => {
  it('lets every edit through on a live, in-zone, unlocked trip', () => {
    expect(gateOf(false)).toBeNull();
    expect(gateOf(true)).toBeNull();
  });

  it('blocks only the rerouting edits out of zone', () => {
    useTripStore.setState({ outOfZone: true });
    expect(gateOf(false)).toBeNull();
    expect(gateOf(true)).toBe('out_of_zone');
  });

  it('reports the most fundamental obstacle first', () => {
    useTripStore.setState({ isLocked: true, outOfZone: true });
    expect(gateOf(true)).toBe('locked');
    useOfflineStore.setState({ apiReachable: false });
    expect(gateOf(true)).toBe('api_unavailable');
    useOfflineStore.setState({ isOnline: false });
    expect(gateOf(false)).toBe('offline');
  });
});
