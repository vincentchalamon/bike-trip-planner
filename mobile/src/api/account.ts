import type { components } from '@btp/core/schema';
import type { Locale } from '../i18n';
import { api } from './client';
import { LD_JSON } from './config';

// GDPR account endpoints (backend Sprint 52): portability + right to erasure.

/** Stable file name for the shared export archive (backend sends JSON-LD). */
export const ACCOUNT_EXPORT_FILENAME = 'bike-trip-planner-data.json';

/**
 * Download the RGPD archive (profile + trips) as raw bytes. The endpoint returns
 * `application/ld+json` with `Content-Disposition: attachment`; read it as an
 * ArrayBuffer (not JSON) so the exact server payload is written to the shared
 * file untouched, mirroring the trip export plumbing (#1047).
 */
export async function fetchAccountExport(): Promise<ArrayBuffer> {
  const { data, error, response } = await api.GET('/users/me/export', {
    headers: { Accept: LD_JSON },
    parseAs: 'arrayBuffer',
  });
  if (error || !response.ok || data === undefined) {
    throw new Error('Failed to export account');
  }
  return data;
}

/**
 * Persist the interface language as the account's locale (`PATCH /users/me`).
 *
 * The server renders alerts and queries third parties in the account's locale
 * rather than in the `Accept-Language` this client sends (ADR-063), so the
 * language picked in the app has to be pushed or the trips keep answering in the
 * previous one.
 *
 * Never throws: switching language is a local, immediate action and must not be
 * held hostage to the network.
 */
export async function updateAccountLocale(locale: Locale): Promise<boolean> {
  try {
    const { response } = await api.PATCH('/users/me', {
      headers: { Accept: LD_JSON, 'Content-Type': 'application/merge-patch+json' },
      body: { locale },
    });
    return response.ok;
  } catch {
    return false;
  }
}

/**
 * The applications this account can still be acted for by, and the way to take that back
 * (#1308).
 *
 * An entry is listed only while a live token still backs it, so the list answers "what can act
 * right now" rather than "what was ever authorized".
 *
 * `name` is text the application published about itself — render it, never build a sentence
 * around it. `host` is the part of its identity it could not choose, which is why the two are
 * always shown together.
 */
export type AuthorizedApplication = components['schemas']['AuthorizedApplication.jsonld'];

/** Resolves to null when the list cannot be read, so the screen can tell empty from broken. */
export async function fetchAuthorizedApplications(): Promise<AuthorizedApplication[] | null> {
  try {
    const { data, error } = await api.GET('/users/me/authorized-applications', {
      headers: { Accept: LD_JSON },
    });
    if (error) return null;
    return data?.member ?? [];
  } catch {
    return null;
  }
}

/**
 * Revoke one authorization. The identifier is the grant's, never the application's URL.
 *
 * Never throws, for the same reason as `deleteAccount()`: a network rejection would otherwise
 * leave the caller's row stuck in `loading`.
 */
export async function revokeAuthorizedApplication(id: string): Promise<boolean> {
  try {
    const { response } = await api.DELETE('/users/me/authorized-applications/{id}', {
      headers: { Accept: LD_JSON },
      params: { path: { id } },
    });
    return response.ok;
  } catch {
    return false;
  }
}

// Anonymise the account (204). Never throws: resolves to false on any failure,
// including a network rejection (offline/timeout) where `api.DELETE` rejects
// before any response — otherwise the caller's button stays stuck in `loading`.
export async function deleteAccount(): Promise<boolean> {
  try {
    const { response } = await api.DELETE('/users/me', { headers: { Accept: LD_JSON } });
    return response.ok;
  } catch {
    return false;
  }
}
