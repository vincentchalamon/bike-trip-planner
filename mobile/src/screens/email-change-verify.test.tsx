/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import { createElement } from 'react';
import { Text } from 'react-native';
import i18n from '../i18n';

const mockReplace = jest.fn();
let mockParams: { '#'?: string } = { '#': 'tok-123' };
let mockLinkingUrl: string | null = null;
jest.mock('expo-router', () => ({
  useLocalSearchParams: () => mockParams,
  useRouter: () => ({ replace: mockReplace }),
  Stack: { Screen: () => null },
}));
jest.mock('expo-linking', () => ({ useLinkingURL: () => mockLinkingUrl }));

jest.mock('../hooks/use-email-change', () => ({ verifyEmailChange: jest.fn() }));
import { verifyEmailChange } from '../hooks/use-email-change';
const mockVerify = verifyEmailChange as jest.MockedFunction<typeof verifyEmailChange>;

const mockRefreshEmail = jest.fn();
jest.mock('../auth/store', () => ({ useAuth: () => ({ refreshEmail: mockRefreshEmail }) }));

import VerifyEmailChangeScreen from '../../app/account/email-change/verify';

beforeAll(async () => {
  await i18n.changeLanguage('fr');
});

beforeEach(() => {
  jest.clearAllMocks();
  mockParams = { '#': 'tok-123' };
  mockLinkingUrl = null;
});

function textInTree(tree: ReturnType<typeof TestRenderer.create>, value: string): boolean {
  return tree.root.findAllByType(Text).some((n: { props: { children?: unknown } }) => {
    const kids = Array.isArray(n.props.children) ? n.props.children : [n.props.children];
    return kids.join('').includes(value);
  });
}

async function render(): Promise<ReturnType<typeof TestRenderer.create>> {
  let tree!: ReturnType<typeof TestRenderer.create>;
  await act(async () => {
    tree = TestRenderer.create(createElement(VerifyEmailChangeScreen));
  });
  return tree;
}

describe('VerifyEmailChangeScreen (#1117)', () => {
  it('commits the change on success: refreshes the email then redirects to the account tab', async () => {
    mockVerify.mockResolvedValue(true);

    await render();

    expect(mockVerify).toHaveBeenCalledWith('tok-123');
    expect(mockRefreshEmail).toHaveBeenCalledTimes(1);
    expect(mockReplace).toHaveBeenCalledWith('/(tabs)/account');
  });

  it('reads the token from the fragment of a custom-scheme link', async () => {
    mockVerify.mockResolvedValue(true);
    mockParams = {};
    mockLinkingUrl = 'biketripplanner://account/email-change/verify#tok-456';

    await render();

    expect(mockVerify).toHaveBeenCalledWith('tok-456');
  });

  it('shows the error state without calling the API when the link has no token', async () => {
    mockParams = {};

    const tree = await render();

    expect(mockVerify).not.toHaveBeenCalled();
    expect(textInTree(tree, i18n.t('account.emailChange.verifyFailedTitle'))).toBe(true);
  });

  it('shows the error state and does not redirect when the token is rejected', async () => {
    mockVerify.mockResolvedValue(false);

    const tree = await render();

    expect(mockRefreshEmail).not.toHaveBeenCalled();
    expect(mockReplace).not.toHaveBeenCalled();
    expect(textInTree(tree, i18n.t('account.emailChange.verifyFailedTitle'))).toBe(true);
  });

  it('still redirects (no error state) when refreshEmail throws after a successful verify', async () => {
    mockVerify.mockResolvedValue(true);
    mockRefreshEmail.mockRejectedValue(new Error('network'));

    const tree = await render();

    expect(mockVerify).toHaveBeenCalledWith('tok-123');
    expect(mockReplace).toHaveBeenCalledWith('/(tabs)/account');
    expect(textInTree(tree, i18n.t('account.emailChange.verifyFailedTitle'))).toBe(false);
  });

  it('runs the verify exactly once even across re-renders (ref guard)', async () => {
    mockVerify.mockResolvedValue(true);

    const tree = await render();
    await act(async () => {
      tree.update(createElement(VerifyEmailChangeScreen));
    });

    expect(mockVerify).toHaveBeenCalledTimes(1);
  });
});
