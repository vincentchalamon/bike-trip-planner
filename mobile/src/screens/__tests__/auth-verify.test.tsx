/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import { Text } from 'react-native';
import i18n from '../../i18n';
import VerifyScreen from '../../../app/auth/verify';

type Renderer = ReturnType<typeof TestRenderer.create>;

const mockReplace = jest.fn();
const mockVerify = jest.fn();
const mockAuth = { authenticated: false, email: null as string | null };

jest.mock('expo-router', () => ({ useRouter: () => ({ replace: mockReplace }) }));
jest.mock('../../auth/link-fragment', () => ({ useLinkFragment: () => 'magic-token' }));
jest.mock('../../auth/store', () => ({
  useAuth: () => ({ verify: mockVerify, ...mockAuth }),
}));

// The verify chain spans several awaits (verify, then the finally); let it settle.
function flush(): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, 0));
}

function texts(renderer: Renderer): string[] {
  return renderer.root
    .findAllByType(Text)
    .flatMap((t: { props: { children?: unknown } }) =>
      Array.isArray(t.props.children) ? t.props.children : [t.props.children],
    )
    .filter((c: unknown): c is string => typeof c === 'string');
}

function press(renderer: Renderer, label: string): Promise<void> {
  const [button] = renderer.root.findAll(
    (node: { props: Record<string, unknown> }) =>
      node.props.label === label && typeof node.props.onPress === 'function',
  );
  expect(button).toBeDefined();
  return act(async () => {
    (button?.props.onPress as () => void)();
    await flush();
  });
}

async function render(): Promise<Renderer> {
  let out!: Renderer;
  await act(async () => {
    out = TestRenderer.create(<VerifyScreen />);
    await flush();
  });
  return out;
}

beforeAll(async () => {
  await i18n.changeLanguage('en');
});

beforeEach(() => {
  jest.clearAllMocks();
  mockVerify.mockReset();
  mockAuth.authenticated = false;
  mockAuth.email = null;
});

describe('VerifyScreen (SEC-15)', () => {
  it('signs in at once when the link was requested from this device', async () => {
    mockVerify.mockResolvedValue({ kind: 'ok' });

    await render();

    expect(mockVerify).toHaveBeenCalledWith('magic-token', false);
    expect(mockReplace).toHaveBeenCalledWith('/(tabs)');
  });

  it('shows the target account and signs in only once the user continues', async () => {
    mockVerify.mockResolvedValueOnce({ kind: 'confirm', email: 'owner@example.com' });
    mockVerify.mockResolvedValueOnce({ kind: 'ok' });

    const screen = await render();

    expect(texts(screen)).toContain('This link signs you in as owner@example.com.');
    expect(mockReplace).not.toHaveBeenCalled();

    await press(screen, 'Continue');

    expect(mockVerify).toHaveBeenLastCalledWith('magic-token', true);
    expect(mockReplace).toHaveBeenCalledWith('/(tabs)');
  });

  it('names the account that a switch would sign out', async () => {
    mockAuth.authenticated = true;
    mockAuth.email = 'me@example.com';
    mockVerify.mockResolvedValue({ kind: 'confirm', email: 'other@example.com' });

    const screen = await render();

    expect(texts(screen).join(' ')).toContain('me@example.com');
    await press(screen, 'Cancel');
    expect(mockReplace).toHaveBeenCalledWith('/(tabs)');
    expect(mockVerify).toHaveBeenCalledTimes(1);
  });

  it('leaves a link to the account already signed in unconsumed', async () => {
    mockAuth.authenticated = true;
    mockAuth.email = 'me@example.com';
    mockVerify.mockResolvedValue({ kind: 'confirm', email: 'me@example.com' });

    await render();

    expect(mockReplace).toHaveBeenCalledWith('/(tabs)');
    expect(mockVerify).toHaveBeenCalledTimes(1);
  });
});
