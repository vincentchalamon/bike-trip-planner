/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import { Alert } from 'react-native';
import AccountDelete from '../../../app/account/delete';
import { DeleteAccountForm } from '../../components/account/DeleteAccountForm';
import { deleteAccount } from '../../api/account';
import { registerDeviceToken, unregisterDeviceToken } from '../../notifications/push';

type Renderer = ReturnType<typeof TestRenderer.create>;

const mockLogout = jest.fn().mockResolvedValue(undefined);

jest.mock('expo-router', () => ({
  Stack: { Screen: () => null },
  useRouter: () => ({ replace: jest.fn(), back: jest.fn() }),
}));
jest.mock('../../api/account', () => ({ deleteAccount: jest.fn() }));
jest.mock('../../auth/store', () => ({ useAuth: () => ({ logout: mockLogout }) }));
jest.mock('../../notifications/push', () => ({
  registerDeviceToken: jest.fn().mockResolvedValue(undefined),
  unregisterDeviceToken: jest.fn().mockResolvedValue(undefined),
}));

const remove = deleteAccount as jest.Mock;

async function confirmDeletion(): Promise<void> {
  let screen!: Renderer;
  await act(async () => {
    screen = TestRenderer.create(<AccountDelete />);
  });
  await act(async () => {
    screen.root.findByType(DeleteAccountForm).props.onConfirm();
    await Promise.resolve();
  });
}

beforeEach(() => jest.clearAllMocks());

describe('AccountDelete (SEC-31)', () => {
  it('unregisters the push token before the account is deleted', async () => {
    remove.mockResolvedValue(true);

    await confirmDeletion();

    const unregistered =
      (unregisterDeviceToken as jest.Mock).mock.invocationCallOrder[0] ?? Infinity;
    expect(unregistered).toBeLessThan(remove.mock.invocationCallOrder[0] ?? 0);
    expect(mockLogout).toHaveBeenCalled();
  });

  it('registers the token again when the deletion fails', async () => {
    remove.mockResolvedValue(false);
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);

    await confirmDeletion();

    expect(registerDeviceToken).toHaveBeenCalled();
    expect(mockLogout).not.toHaveBeenCalled();
  });
});
