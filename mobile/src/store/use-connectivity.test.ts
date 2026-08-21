/// <reference types="jest" />
import { createElement } from 'react';
import TestRenderer, { act } from 'react-test-renderer';
import { useConnectivity } from './use-connectivity';
import { useOfflineStore } from './offline-store';

type NetInfoState = { isConnected: boolean | null; isInternetReachable: boolean | null };
type NetInfoListener = (state: NetInfoState) => void;

const mockUnsubscribe = jest.fn();
let mockCapturedListener: NetInfoListener | undefined;

jest.mock('@react-native-community/netinfo', () => ({
  __esModule: true,
  default: {
    addEventListener: jest.fn((listener: NetInfoListener) => {
      mockCapturedListener = listener;
      return mockUnsubscribe;
    }),
  },
}));

function Harness(): null {
  useConnectivity();
  return null;
}

async function mount(): Promise<{ unmount: () => void }> {
  let renderer!: ReturnType<typeof TestRenderer.create>;
  await act(async () => {
    renderer = TestRenderer.create(createElement(Harness));
  });
  return { unmount: () => act(() => renderer.unmount()) };
}

function emit(state: NetInfoState): void {
  act(() => mockCapturedListener!(state));
}

beforeEach(() => {
  mockUnsubscribe.mockClear();
  mockCapturedListener = undefined;
  useOfflineStore.setState({ isOnline: true });
});

describe('useConnectivity — NetInfo to isOnline three-state mapping (#1146)', () => {
  it('treats isInternetReachable: null (still probing) as online', async () => {
    await mount();
    emit({ isConnected: true, isInternetReachable: null });
    expect(useOfflineStore.getState().isOnline).toBe(true);
  });

  it('treats isInternetReachable: false as offline', async () => {
    await mount();
    emit({ isConnected: true, isInternetReachable: false });
    expect(useOfflineStore.getState().isOnline).toBe(false);
  });

  it('treats isInternetReachable: true as online', async () => {
    await mount();
    emit({ isConnected: true, isInternetReachable: true });
    expect(useOfflineStore.getState().isOnline).toBe(true);
  });

  it('treats isConnected: false as offline regardless of reachability', async () => {
    await mount();
    emit({ isConnected: false, isInternetReachable: null });
    expect(useOfflineStore.getState().isOnline).toBe(false);
  });

  it('unsubscribes the NetInfo listener on unmount', async () => {
    const { unmount } = await mount();
    expect(mockUnsubscribe).not.toHaveBeenCalled();
    unmount();
    expect(mockUnsubscribe).toHaveBeenCalledTimes(1);
  });
});
