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
  if (error || !response.ok) {
    throw new Error('Failed to export account');
  }
  return data;
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
