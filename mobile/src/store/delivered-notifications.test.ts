/// <reference types="jest" />
import * as SecureStore from 'expo-secure-store';
import { useDeliveredNotifications } from './delivered-notifications';

jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn(),
  setItemAsync: jest.fn(),
}));

const getItem = SecureStore.getItemAsync as jest.Mock;
const setItem = SecureStore.setItemAsync as jest.Mock;

beforeEach(() => {
  getItem.mockReset();
  setItem.mockReset();
  useDeliveredNotifications.setState({ delivered: new Set<string>(), hydrated: false });
});

describe('useDeliveredNotifications', () => {
  it('marks an id delivered and persists the whole set', () => {
    useDeliveredNotifications.getState().markDelivered('btp:tripNoDate:t1');
    expect(useDeliveredNotifications.getState().delivered.has('btp:tripNoDate:t1')).toBe(true);
    expect(setItem).toHaveBeenCalledWith(
      'btp_delivered_notifications',
      JSON.stringify(['btp:tripNoDate:t1']),
    );
  });

  it('is idempotent: marking the same id twice persists once', () => {
    const state = useDeliveredNotifications.getState();
    state.markDelivered('id-1');
    state.markDelivered('id-1');
    expect(setItem).toHaveBeenCalledTimes(1);
  });

  it('clears an id and persists the remaining set', () => {
    useDeliveredNotifications.setState({ delivered: new Set(['a', 'b']), hydrated: true });
    useDeliveredNotifications.getState().clearDelivered('a');
    expect(useDeliveredNotifications.getState().delivered.has('a')).toBe(false);
    expect(setItem).toHaveBeenLastCalledWith(
      'btp_delivered_notifications',
      JSON.stringify(['b']),
    );
  });

  it('clearing an absent id is a no-op', () => {
    useDeliveredNotifications.getState().clearDelivered('missing');
    expect(setItem).not.toHaveBeenCalled();
  });

  it('load hydrates the set from storage once', async () => {
    getItem.mockResolvedValue(JSON.stringify(['x', 'y']));
    await useDeliveredNotifications.getState().load();
    expect(useDeliveredNotifications.getState()).toMatchObject({ hydrated: true });
    expect([...useDeliveredNotifications.getState().delivered].sort()).toEqual(['x', 'y']);

    getItem.mockResolvedValue(JSON.stringify(['z']));
    await useDeliveredNotifications.getState().load();
    // Already hydrated: second load is a no-op.
    expect(getItem).toHaveBeenCalledTimes(1);
    expect(useDeliveredNotifications.getState().delivered.has('z')).toBe(false);
  });

  it('load falls back to an empty set for a malformed payload', async () => {
    getItem.mockResolvedValue('not json');
    await useDeliveredNotifications.getState().load();
    expect(useDeliveredNotifications.getState().delivered.size).toBe(0);
  });
});
