/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import { createElement } from 'react';
import * as Location from 'expo-location';
import { useForegroundLocation, type ForegroundLocation } from './use-foreground-location';

jest.mock('expo-location', () => ({
  Accuracy: { Balanced: 3 },
  requestForegroundPermissionsAsync: jest.fn(),
  watchPositionAsync: jest.fn(),
}));

const requestPerm = Location.requestForegroundPermissionsAsync as jest.Mock;
const watch = Location.watchPositionAsync as jest.Mock;

function Harness({ onRender }: { onRender: (v: ForegroundLocation) => void }): null {
  onRender(useForegroundLocation());
  return null;
}

async function flush(): Promise<void> {
  await act(async () => {
    for (let i = 0; i < 8; i += 1) await Promise.resolve();
  });
}

beforeEach(() => {
  requestPerm.mockReset();
  watch.mockReset();
});

describe('useForegroundLocation', () => {
  it('subscribes to positions when permission is granted and removes the subscription on unmount', async () => {
    let callback: ((loc: { coords: unknown }) => void) | undefined;
    const remove = jest.fn();
    requestPerm.mockResolvedValue({ status: 'granted' });
    watch.mockImplementation(async (_opts: unknown, cb: (loc: { coords: unknown }) => void) => {
      callback = cb;
      return { remove };
    });

    const states: ForegroundLocation[] = [];
    let tree!: ReturnType<typeof TestRenderer.create>;
    await act(async () => {
      tree = TestRenderer.create(
        createElement(Harness, { onRender: (v) => states.push(v) }),
      );
    });
    await flush();

    // A fix arrives from the watcher.
    act(() => callback?.({ coords: { latitude: 45.5, longitude: 6.1 } }));

    const last = states[states.length - 1];
    expect(watch).toHaveBeenCalledTimes(1);
    expect(last.permission).toBe('granted');
    expect(last.position).toEqual({ latitude: 45.5, longitude: 6.1 });

    // Unmount stops tracking (foreground-only, no background).
    act(() => tree.unmount());
    expect(remove).toHaveBeenCalledTimes(1);
  });

  it('reports a denied permission and never subscribes when the request is refused', async () => {
    requestPerm.mockResolvedValue({ status: 'denied' });

    const states: ForegroundLocation[] = [];
    await act(async () => {
      TestRenderer.create(createElement(Harness, { onRender: (v) => states.push(v) }));
    });
    await flush();

    const last = states[states.length - 1];
    expect(last.permission).toBe('denied');
    expect(last.position).toBeNull();
    expect(watch).not.toHaveBeenCalled();
  });

  it('falls back to denied when the OS location API throws (e.g. Location Services off)', async () => {
    requestPerm.mockResolvedValue({ status: 'granted' });
    watch.mockRejectedValue(new Error('Location services disabled'));

    const states: ForegroundLocation[] = [];
    await act(async () => {
      TestRenderer.create(createElement(Harness, { onRender: (v) => states.push(v) }));
    });
    await flush();

    const last = states[states.length - 1];
    expect(last.permission).toBe('denied');
    expect(last.position).toBeNull();
  });
});
