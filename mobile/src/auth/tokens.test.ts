/// <reference types="jest" />
import * as SecureStore from 'expo-secure-store';
import { setTokens } from './tokens';

jest.mock('expo-secure-store', () => ({
  WHEN_UNLOCKED_THIS_DEVICE_ONLY: 6,
  getItemAsync: jest.fn(),
  setItemAsync: jest.fn(),
  deleteItemAsync: jest.fn(),
}));

const setItem = SecureStore.setItemAsync as jest.Mock;

describe('setTokens', () => {
  it('keeps both tokens on this device only', async () => {
    await setTokens('jwt-value', 'refresh-value');

    const options = { keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY };
    expect(setItem).toHaveBeenCalledWith('btp_jwt', 'jwt-value', options);
    expect(setItem).toHaveBeenCalledWith('btp_refresh', 'refresh-value', options);
  });
});
