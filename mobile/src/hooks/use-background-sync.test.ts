/// <reference types="jest" />
import { createElement } from 'react';
import TestRenderer, { act } from 'react-test-renderer';
import { AppState } from 'react-native';
import { useBackgroundTripSync } from './use-background-sync';
import { useOfflineStore } from '../store/offline-store';

jest.mock('../api/trips', () => ({ fetchTripDetail: jest.fn(), fetchTripRoute: jest.fn() }));
jest.mock('../store/trip-cache', () => ({ syncCachedTrips: jest.fn() }));
import { syncCachedTrips } from '../store/trip-cache';
const mockSync = syncCachedTrips as jest.MockedFunction<typeof syncCachedTrips>;

function Harness(): null {
  useBackgroundTripSync();
  return null;
}

const mounted: ReturnType<typeof TestRenderer.create>[] = [];

async function mount(): Promise<{ unmount: () => void }> {
  let renderer!: ReturnType<typeof TestRenderer.create>;
  await act(async () => {
    renderer = TestRenderer.create(createElement(Harness));
  });
  mounted.push(renderer);
  return { unmount: () => act(() => renderer.unmount()) };
}

beforeEach(() => {
  mockSync.mockClear();
  mockSync.mockResolvedValue(undefined);
  useOfflineStore.setState({ isOnline: true });
});

// Each test mounts its own Harness; unmount it so the next test's store
// updates don't re-render a stale tree outside act().
afterEach(() => {
  act(() => {
    mounted.splice(0).forEach((renderer) => renderer.unmount());
  });
});

describe('useBackgroundTripSync (#1147)', () => {
  it('re-syncs when isOnline flips false -> true', async () => {
    useOfflineStore.setState({ isOnline: false });
    await mount();
    mockSync.mockClear();

    await act(async () => {
      useOfflineStore.setState({ isOnline: true });
    });
    expect(mockSync).toHaveBeenCalledTimes(1);
  });

  it('does not re-sync when isOnline flips true -> false', async () => {
    await mount();
    mockSync.mockClear();

    await act(async () => {
      useOfflineStore.setState({ isOnline: false });
    });
    expect(mockSync).not.toHaveBeenCalled();
  });

  it('re-syncs when AppState becomes active', async () => {
    let listener: (state: string) => void = () => {};
    const addSpy = jest.spyOn(AppState, 'addEventListener').mockImplementation((_event, cb) => {
      listener = cb as (state: string) => void;
      return { remove: jest.fn() } as never;
    });

    await mount();
    mockSync.mockClear();

    await act(async () => {
      listener('active');
    });
    expect(mockSync).toHaveBeenCalledTimes(1);

    addSpy.mockRestore();
  });

  it('does not re-sync for other AppState transitions', async () => {
    let listener: (state: string) => void = () => {};
    const addSpy = jest.spyOn(AppState, 'addEventListener').mockImplementation((_event, cb) => {
      listener = cb as (state: string) => void;
      return { remove: jest.fn() } as never;
    });

    await mount();
    mockSync.mockClear();

    await act(async () => {
      listener('background');
    });
    expect(mockSync).not.toHaveBeenCalled();

    addSpy.mockRestore();
  });

  it('removes the AppState listener on unmount', async () => {
    const removeSpy = jest.fn();
    const addSpy = jest
      .spyOn(AppState, 'addEventListener')
      .mockImplementation(() => ({ remove: removeSpy }) as never);

    const { unmount } = await mount();
    expect(removeSpy).not.toHaveBeenCalled();
    unmount();
    expect(removeSpy).toHaveBeenCalledTimes(1);

    addSpy.mockRestore();
  });
});
