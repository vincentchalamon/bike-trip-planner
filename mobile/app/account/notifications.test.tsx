/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import type { ReactElement } from 'react';
import { AppState, Text } from 'react-native';
import * as Notifications from 'expo-notifications';
import i18n from '../../src/i18n';
import { NOTIFICATION_DEFAULTS, selectActiveCount, useNotificationPrefs } from '../../src/store/notification-prefs';
import AccountNotifications from './notifications';

jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn().mockResolvedValue(null),
  setItemAsync: jest.fn(),
}));

jest.mock('expo-notifications', () => ({
  getPermissionsAsync: jest.fn(),
  requestPermissionsAsync: jest.fn(),
}));

// Stub the router Stack.Screen (no navigator mounted in the test tree).
jest.mock('expo-router', () => ({ Stack: { Screen: () => null } }));

const getPerms = Notifications.getPermissionsAsync as jest.Mock;
const requestPerms = Notifications.requestPermissionsAsync as jest.Mock;

function texts(node: any): string[] {
  return node.root.findAllByType(Text).flatMap((t: any) => {
    const kids = Array.isArray(t.props.children) ? t.props.children : [t.props.children];
    return kids.filter((c: unknown): c is string => typeof c === 'string');
  });
}

async function render(element: ReactElement): Promise<any> {
  let out: any;
  await act(async () => {
    out = TestRenderer.create(element);
    await Promise.resolve();
  });
  return out;
}

beforeAll(async () => {
  await i18n.changeLanguage('fr');
});

beforeEach(() => {
  getPerms.mockReset();
  requestPerms.mockReset();
  getPerms.mockResolvedValue({ granted: true, canAskAgain: false });
  useNotificationPrefs.setState({ enabled: { ...NOTIFICATION_DEFAULTS }, hydrated: false });
});

describe('AccountNotifications screen', () => {
  it('renders the granted permission banner and all five categories', async () => {
    const tree = await render(<AccountNotifications />);
    const labels = texts(tree);
    expect(labels).toContain('Autorisées par le système');
    expect(labels).toContain("Météo & sécurité d'étape");
    expect(labels).toContain('Analyse terminée ou échouée');
    expect(labels).toContain('Synchro hors-ligne non prête');
    expect(labels).toContain('Voyage sans date');
    expect(labels).toContain("Ouverture d'une nouvelle zone");
    expect(labels).toContain('opt-in · désactivé par défaut');
  });

  it('re-checks the permission when the app returns to the foreground', async () => {
    let appStateListener: (s: string) => void = () => {};
    const addSpy = jest
      .spyOn(AppState, 'addEventListener')
      .mockImplementation((_event, cb) => {
        appStateListener = cb as (s: string) => void;
        return { remove: jest.fn() } as never;
      });

    // Mount while permission can still be asked → prompt banner.
    getPerms.mockResolvedValue({ granted: false, canAskAgain: true });
    const tree = await render(<AccountNotifications />);
    expect(texts(tree)).toContain('Active les notifications système');

    // User granted it in Android settings and came back to the app.
    getPerms.mockResolvedValue({ granted: true, canAskAgain: false });
    await act(async () => {
      appStateListener('active');
      await Promise.resolve();
    });
    expect(texts(tree)).toContain('Autorisées par le système');

    addSpy.mockRestore();
  });

  it('renders the denied banner when permission is refused and not re-askable', async () => {
    getPerms.mockResolvedValue({ granted: false, canAskAgain: false });
    const tree = await render(<AccountNotifications />);
    const labels = texts(tree);
    expect(labels).toContain('Bloquées par le système');
    expect(labels).toContain('Réactive-les dans les réglages Android.');
    // No request button in the denied state — the OS won't prompt again.
    expect(labels).not.toContain('Autoriser');
  });

  it('requests permission from the prompt banner and updates on the result', async () => {
    getPerms.mockResolvedValue({ granted: false, canAskAgain: true });
    requestPerms.mockResolvedValue({ granted: true, canAskAgain: false });
    const tree = await render(<AccountNotifications />);
    expect(texts(tree)).toContain('Active les notifications système');

    const allowBtn = tree.root.find(
      (n: any) => n.props.label === 'Autoriser' && typeof n.props.onPress === 'function',
    );
    await act(async () => {
      allowBtn.props.onPress();
      await Promise.resolve();
    });

    expect(requestPerms).toHaveBeenCalledTimes(1);
    expect(texts(tree)).toContain('Autorisées par le système');
  });

  it('toggling the weather switch flips the store', async () => {
    const tree = await render(<AccountNotifications />);
    expect(selectActiveCount(useNotificationPrefs.getState())).toBe(4);

    const sw = tree.root
      .findAll((n: any) => n.props.accessibilityRole === 'switch')
      .find((n: any) => n.props.accessibilityLabel === "Météo & sécurité d'étape");
    act(() => sw.props.onPress());

    expect(useNotificationPrefs.getState().enabled.weatherSafety).toBe(false);
    expect(selectActiveCount(useNotificationPrefs.getState())).toBe(3);
  });
});
