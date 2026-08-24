import { File, Paths } from 'expo-file-system';
import * as Sharing from 'expo-sharing';

// Write the fetched bytes to a cache file and hand it to the native share sheet
// (save to Files / send to another app). Shared by the trip GPX/FIT export
// (use-export.ts) and the RGPD account archive (use-account-export.ts) so the
// write→share ordering fix lives in one place. Awaits the write before sharing:
// `File#write` is currently a synchronous JSI call in expo-file-system, but
// awaiting it is a no-op on that value and keeps the ordering correct if a future
// SDK makes it return a Promise. Extracted so it is unit-testable without a device
// (mocks expo-file-system / expo-sharing).
export async function writeAndShareFile(
  bytes: ArrayBuffer,
  filename: string,
  mimeType: string,
): Promise<void> {
  const file = new File(Paths.cache, filename);
  file.create({ intermediates: true, overwrite: true });
  await file.write(new Uint8Array(bytes));
  if (!(await Sharing.isAvailableAsync())) {
    throw new Error('Sharing is not available on this device');
  }
  try {
    await Sharing.shareAsync(file.uri, { mimeType });
  } finally {
    // Delete the temp export once the share sheet is done. It matters most for the
    // RGPD account archive (profile, email, every trip) which must not linger in
    // the cache dir, but GPX/FIT exports are cleaned up the same way (#1174).
    try {
      file.delete();
    } catch {
      // ignore: best-effort cleanup of the temp export file.
    }
  }
}
