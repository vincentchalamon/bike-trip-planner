import { useCallback, useState } from 'react';
import { File, Paths } from 'expo-file-system';
import * as Sharing from 'expo-sharing';
import { ACCOUNT_EXPORT_FILENAME, fetchAccountExport } from '../api/account';

// Not to be confused with `use-export.ts` (trip/stage GPX/FIT export). This hook
// handles the RGPD account archive (profile + trips as a single JSON file).

const JSON_MIME = 'application/json';

// Write the fetched bytes to a cache file and hand it to the native share sheet.
// Awaits the write before sharing (see the ordering note in use-export.ts).
export async function writeAndShareAccount(bytes: ArrayBuffer): Promise<void> {
  const file = new File(Paths.cache, ACCOUNT_EXPORT_FILENAME);
  file.create({ intermediates: true, overwrite: true });
  await file.write(new Uint8Array(bytes));
  if (!(await Sharing.isAvailableAsync())) {
    throw new Error('Sharing is not available on this device');
  }
  await Sharing.shareAsync(file.uri, { mimeType: JSON_MIME });
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
  exportAccount: () => Promise<void>;
}

// Tracks the single in-flight export so the button can show a spinner; calls
// `onFailure` when the export could not complete (network error or no share
// target on the device).
export function useAccountExport(onFailure: () => void): UseAccountExport {
  const [exporting, setExporting] = useState(false);

  const exportAccount = useCallback(async () => {
    setExporting(true);
    const ok = await runAccountExport();
    setExporting(false);
    if (!ok) onFailure();
  }, [onFailure]);

  return { exporting, exportAccount };
}
