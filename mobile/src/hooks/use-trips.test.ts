/// <reference types="jest" />
import { Alert } from 'react-native';
import {
  confirmDeleteTrip,
  hasActiveFilter,
  hasMorePages,
  runDeleteTrip,
  runLoadTrips,
} from './use-trips';

jest.mock('../api/trips', () => ({
  fetchTrips: jest.fn(),
  deleteTrip: jest.fn(),
}));
import { deleteTrip, fetchTrips } from '../api/trips';
const mockFetch = fetchTrips as jest.MockedFunction<typeof fetchTrips>;
const mockDelete = deleteTrip as jest.MockedFunction<typeof deleteTrip>;

beforeEach(() => jest.clearAllMocks());

describe('runLoadTrips (#1036)', () => {
  it('returns the page + total on success', async () => {
    mockFetch.mockResolvedValue({ items: [{ id: 't1' }], totalItems: 30 } as never);
    const res = await runLoadTrips(1, { title: 'foo' });
    expect(mockFetch).toHaveBeenCalledWith(1, { title: 'foo' });
    expect(res.items).toHaveLength(1);
    expect(res.totalItems).toBe(30);
    expect(res.error).toBeNull();
  });

  it('returns an empty page + a message when the fetch throws', async () => {
    mockFetch.mockRejectedValue(new Error('boom'));
    const res = await runLoadTrips(2, {});
    expect(res.items).toEqual([]);
    expect(res.totalItems).toBe(0);
    expect(res.error).toBe('Impossible de charger les voyages.');
  });
});

describe('runDeleteTrip (#1036)', () => {
  it('resolves null on a successful delete', async () => {
    mockDelete.mockResolvedValue({ ok: true, status: 204 });
    expect(await runDeleteTrip('t1')).toBeNull();
  });

  it('resolves a message when the delete fails (incl. 404 non-owner masking)', async () => {
    mockDelete.mockResolvedValue({ ok: false, status: 404 });
    expect(await runDeleteTrip('t1')).toBe('La suppression a échoué.');
  });
});

describe('pagination + filter predicates (#1036)', () => {
  it('hasMorePages is true while fewer items are loaded than the total', () => {
    expect(hasMorePages(12, 30)).toBe(true);
    expect(hasMorePages(30, 30)).toBe(false);
    expect(hasMorePages(0, 0)).toBe(false);
  });

  it('hasActiveFilter distinguishes "no results" from "no trips"', () => {
    expect(hasActiveFilter({ title: '', startDate: '', endDate: '' })).toBe(false);
    expect(hasActiveFilter({ title: 'alps' })).toBe(true);
    expect(hasActiveFilter({ startDate: '2026-01-01' })).toBe(true);
    expect(hasActiveFilter({ endDate: '2026-02-01' })).toBe(true);
  });
});

describe('confirmDeleteTrip (#1036)', () => {
  it('opens a confirm dialog and runs onConfirm only on the destructive button', () => {
    const spy = jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    const onConfirm = jest.fn();
    confirmDeleteTrip({
      title: 'Delete?',
      message: 'Gone forever',
      cancel: 'Cancel',
      confirm: 'Delete',
      onConfirm,
    });

    expect(spy).toHaveBeenCalledTimes(1);
    const [title, , buttons] = spy.mock.calls[0] as [
      string,
      string,
      { text: string; style?: string; onPress?: () => void }[],
    ];
    expect(title).toBe('Delete?');
    const cancel = buttons.find((b) => b.style === 'cancel');
    const destructive = buttons.find((b) => b.style === 'destructive');
    expect(cancel).toBeDefined();
    expect(destructive).toBeDefined();
    expect(onConfirm).not.toHaveBeenCalled();
    destructive!.onPress?.();
    expect(onConfirm).toHaveBeenCalledTimes(1);
    spy.mockRestore();
  });
});
