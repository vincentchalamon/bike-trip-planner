/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import type { ReactElement } from 'react';
import { Text } from 'react-native';
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
