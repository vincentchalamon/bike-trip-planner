/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import type { ReactElement } from 'react';
import { Alert, Text } from 'react-native';
import i18n from '../../i18n';
import {
  fetchAuthorizedApplications,
  revokeAuthorizedApplication,
} from '../../api/account';
import AccountApplications from '../../../app/account/applications';

jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn().mockResolvedValue(null),
  setItemAsync: jest.fn(),
}));

// Stub the router Stack.Screen (no navigator mounted in the test tree).
jest.mock('expo-router', () => ({ Stack: { Screen: () => null } }));

jest.mock('../../api/account', () => ({
  fetchAuthorizedApplications: jest.fn(),
  revokeAuthorizedApplication: jest.fn(),
}));

const fetchApplications = fetchAuthorizedApplications as jest.Mock;
const revoke = revokeAuthorizedApplication as jest.Mock;

function texts(node: any): string[] {
  return node.root.findAllByType(Text).flatMap((t: any) => {
    const kids = Array.isArray(t.props.children) ? t.props.children : [t.props.children];
    return kids.filter((c: unknown): c is string => typeof c === 'string');
  });
}

// The destructive entry of the Alert.alert() the screen opened — the confirmation the
// user has to accept before anything is revoked.
function confirmation(alert: jest.SpyInstance): { onPress: () => void } {
  const actions = (alert.mock.calls[0]?.[2] ?? []) as { style?: string; onPress: () => void }[];
  const destructive = actions.find((action) => action.style === 'destructive');
  expect(destructive).toBeDefined();
  return destructive as { onPress: () => void };
}

async function render(element: ReactElement): Promise<any> {
  let out: any;
  await act(async () => {
    out = TestRenderer.create(element);
    await Promise.resolve();
  });
  return out;
}

function application(overrides: Record<string, unknown> = {}) {
  return {
    id: '0199a0d0-0000-7000-8000-000000000001',
    name: 'Example Agent',
    host: 'agent.example.com',
    scopes: ['trips:read'],
    authorizedAt: '2026-09-20T10:00:00+00:00',
    lastUsedAt: null,
    ...overrides,
  };
}

beforeAll(async () => {
  await i18n.changeLanguage('fr');
});

beforeEach(() => {
  fetchApplications.mockReset();
  revoke.mockReset();
  revoke.mockResolvedValue(true);
});

describe('AccountApplications screen', () => {
  it('shows the host beside the name, and the scope as it was granted', async () => {
    fetchApplications.mockResolvedValue([application()]);

    const labels = texts(await render(<AccountApplications />));

    expect(labels).toContain('Example Agent');
    // The host is the part of the identity the application could not choose, so it
    // must be on screen next to the name it chose for itself.
    expect(labels).toContain('agent.example.com');
    expect(labels).toContain('Consulter tes voyages et leurs étapes');
  });

  it('falls back to the raw scope when the catalogue does not describe it', async () => {
    fetchApplications.mockResolvedValue([application({ scopes: ['trips:retired'] })]);

    const labels = texts(await render(<AccountApplications />));

    // Shown as granted rather than swallowed: these are historical scopes, which the
    // current catalogue is not obliged to know.
    expect(labels).toContain('trips:retired');
  });

  it('says never used rather than showing an empty date', async () => {
    fetchApplications.mockResolvedValue([application()]);

    const labels = texts(await render(<AccountApplications />));

    expect(labels.some((label) => label.includes('jamais utilisée'))).toBe(true);
  });

  it('tells an empty account from a broken call', async () => {
    fetchApplications.mockResolvedValue([]);
    expect(texts(await render(<AccountApplications />))).toContain('Aucune application');

    fetchApplications.mockResolvedValue(null);
    expect(texts(await render(<AccountApplications />))).toContain(
      'Impossible de charger tes applications autorisées.',
    );
  });

  it('revokes only after the confirmation is accepted, and drops the row', async () => {
    fetchApplications.mockResolvedValue([application()]);
    const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => {});

    const tree = await render(<AccountApplications />);
    const button = tree.root.findAll(
      (node: any) => node.props?.accessibilityRole === 'button' && node.props?.onPress,
    );
    await act(async () => {
      // The revoke button is the only one on a single-row screen.
      button[button.length - 1].props.onPress();
    });

    // Opening the dialog must not revoke anything on its own.
    expect(revoke).not.toHaveBeenCalled();

    await act(async () => {
      confirmation(alert).onPress();
      await Promise.resolve();
    });

    expect(revoke).toHaveBeenCalledWith('0199a0d0-0000-7000-8000-000000000001');
    expect(texts(tree)).not.toContain('Example Agent');
    alert.mockRestore();
  });

  it('keeps the row when the revocation fails', async () => {
    fetchApplications.mockResolvedValue([application()]);
    revoke.mockResolvedValue(false);
    const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => {});

    const tree = await render(<AccountApplications />);
    const buttons = tree.root.findAll(
      (node: any) => node.props?.accessibilityRole === 'button' && node.props?.onPress,
    );
    await act(async () => {
      buttons[buttons.length - 1].props.onPress();
    });
    await act(async () => {
      confirmation(alert).onPress();
      await Promise.resolve();
    });

    expect(texts(tree)).toContain('Example Agent');
    alert.mockRestore();
  });
});
