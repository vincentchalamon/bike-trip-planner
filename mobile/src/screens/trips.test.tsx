/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import { createElement } from 'react';
import { Alert } from 'react-native';
import i18n from '../i18n';
import type { UseTrips } from '../hooks/use-trips';

jest.mock('expo-router', () => ({ useRouter: () => ({ push: jest.fn() }) }));

jest.mock('../hooks/use-trips', () => {
  const actual = jest.requireActual('../hooks/use-trips');
  return { ...actual, useTrips: jest.fn() };
});
import { useTrips } from '../hooks/use-trips';
import Trips from '../../app/(tabs)/index';

const mockUseTrips = useTrips as jest.MockedFunction<typeof useTrips>;

function deferred<T>(): { promise: Promise<T>; resolve: (v: T) => void } {
  let resolve!: (v: T) => void;
  const promise = new Promise<T>((r) => {
    resolve = r;
  });
  return { promise, resolve };
}

const baseTrips: UseTrips = {
  trips: [{ id: 't1', title: 'Test', stageCount: 1, totalDistance: 10, status: 'draft' } as never],
  loading: false,
  loadingMore: false,
  refreshing: false,
  error: null,
  title: '',
  startDate: '',
  endDate: '',
  hasActiveFilter: false,
  canLoadMore: false,
  setTitle: jest.fn(),
  setStartDate: jest.fn(),
  setEndDate: jest.fn(),
  reload: jest.fn(),
  loadMore: jest.fn(),
  remove: jest.fn(),
  duplicate: jest.fn(),
};

// The Copy Pressable is labelled with trips.duplicateA11y('Test').
function findDuplicateButton(root: any): any {
  return root.find(
    (n: any) =>
      n.props?.accessibilityRole === 'button' &&
      typeof n.props?.onPress === 'function' &&
      n.props?.accessibilityLabel === i18n.t('trips.duplicateA11y', { title: 'Test' }),
  );
}

let renderer: any;
function render(): any {
  act(() => {
    renderer = TestRenderer.create(createElement(Trips));
  });
  return renderer.root;
}

beforeAll(async () => {
  await i18n.changeLanguage('fr');
});

beforeEach(() => {
  jest.clearAllMocks();
});

afterEach(() => {
  act(() => renderer?.unmount());
});

describe('Trips list — Duplicate re-entrance guard (#1043)', () => {
  it('ignores a second tap while a duplication is in flight (one POST, not two)', async () => {
    const dup = deferred<string | null>();
    const duplicate = jest.fn(() => dup.promise);
    mockUseTrips.mockReturnValue({ ...baseTrips, duplicate });

    const root = render();
    expect(findDuplicateButton(root).props.disabled).toBe(false);

    // First tap: duplication starts and is in flight.
    await act(async () => {
      findDuplicateButton(root).props.onPress();
    });
    expect(duplicate).toHaveBeenCalledTimes(1);
    // The button is now disabled...
    expect(findDuplicateButton(root).props.disabled).toBe(true);

    // ...and a second tap (re-entrance) is a no-op: still a single call.
    await act(async () => {
      findDuplicateButton(root).props.onPress();
    });
    expect(duplicate).toHaveBeenCalledTimes(1);

    // Once it resolves, the button re-enables.
    await act(async () => {
      dup.resolve('t2');
    });
    expect(findDuplicateButton(root).props.disabled).toBe(false);
  });

  it('re-enables the button and alerts when the duplication fails', async () => {
    const spy = jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    const duplicate = jest.fn().mockResolvedValue(null);
    mockUseTrips.mockReturnValue({ ...baseTrips, duplicate });

    const root = render();
    await act(async () => {
      findDuplicateButton(root).props.onPress();
    });

    expect(duplicate).toHaveBeenCalledTimes(1);
    expect(spy).toHaveBeenCalledWith(
      i18n.t('trips.duplicateFailedTitle'),
      i18n.t('trips.duplicateFailed'),
    );
    expect(findDuplicateButton(root).props.disabled).toBe(false);
    spy.mockRestore();
  });
});
