import { useCallback, useState } from 'react';
import { ACCOUNT_EXPORT_FILENAME, fetchAccountExport } from '../api/account';
import { writeAndShareFile } from '../lib/fs-share';

// Not to be confused with `use-export.ts` (trip/stage GPX/FIT export). This hook
// handles the RGPD account archive (profile + trips as a single JSON file).

const JSON_MIME = 'application/json';

// Write the fetched bytes to a cache file and hand it to the native share sheet
// (shared plumbing in fs-share.ts, same as the trip GPX/FIT export).
export async function writeAndShareAccount(bytes: ArrayBuffer): Promise<void> {
  return writeAndShareFile(bytes, ACCOUNT_EXPORT_FILENAME, JSON_MIME);
}

// Fetch + write + share the archive. Never throws: resolves to false on failure
// so the caller can surface it (mirrors runExportTrip).
export async function runAccountExport(): Promise<boolean> {
  try {
    const bytes = await fetchAccountExport();
    await writeAndShareAccount(bytes);
    return true;
  } catch {
    return false;
  }
}

export interface UseAccountExport {
  exporting: boolean;
  exportAccount: () => Promise<boolean>;
}

// Tracks the single in-flight export so the button can show a spinner; calls
// `onFailure` when the export could not complete (network error or no share
// target on the device). Resolves to the outcome so the caller can also surface
// an in-app success confirmation.
export function useAccountExport(onFailure: () => void): UseAccountExport {
  const [exporting, setExporting] = useState(false);

  const exportAccount = useCallback(async (): Promise<boolean> => {
    setExporting(true);
    const ok = await runAccountExport();
    setExporting(false);
    if (!ok) onFailure();
    return ok;
  }, [onFailure]);

  return { exporting, exportAccount };
}
