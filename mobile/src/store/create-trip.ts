import * as DocumentPicker from 'expo-document-picker';
import { createTrip, uploadGpx, type GpxFile } from '../api/trips';
import { normalizeStatus, type MutationFailure } from './gating';
import { useOfflineStore } from './offline-store';

/**
 * Supported source URL patterns, mirroring the backend `RouteFetcherRegistry`
 * strategies (CLAUDE.md, Security Constraints). The backend stays the source of
 * truth; these give fast client-side feedback before the POST is sent. Kept in
 * lockstep with the web `SUPPORTED_SOURCE_PATTERNS`.
 */
export const SUPPORTED_SOURCE_PATTERNS: readonly RegExp[] = [
  /^https:\/\/www\.komoot\.com\/([a-z]{2}-[a-z]{2}\/)?tour\/\d+/,
  /^https:\/\/www\.komoot\.com\/([a-z]{2}-[a-z]{2}\/)?collection\/\d+/,
  /^https:\/\/www\.strava\.com\/routes\/\d+/,
  /^https:\/\/ridewithgps\.com\/routes\/\d+/,
] as const;

/** Whether a URL matches one of the supported route source patterns. */
export function isSupportedSourceUrl(value: string): boolean {
  const trimmed = value.trim();
  if (!trimmed) return false;
  return SUPPORTED_SOURCE_PATTERNS.some((pattern) => pattern.test(trimmed));
}

/**
 * Create a trip from a pasted / shared link. Blocked while offline (like every
 * other mutation, see gating.ts); a backend rejection (invalid or unsupported
 * URL) is normalized to a {@link MutationFailure}. Returns the new trip id, or
 * null with the failure reported. Client-side URL validation is the screen's
 * job — this runner still forwards whatever the backend answers.
 */
export async function runCreateTrip(
  sourceUrl: string,
  onFailure: (reason: MutationFailure) => void,
): Promise<string | null> {
  if (!useOfflineStore.getState().isOnline) {
    onFailure('offline');
    return null;
  }
  try {
    const { id, status } = await createTrip(sourceUrl.trim());
    if (!id) {
      onFailure(normalizeStatus(status));
      return null;
    }
    return id;
  } catch {
    onFailure('network');
    return null;
  }
}

/**
 * Open the system file picker restricted to GPX files. Returns the picked asset,
 * or null when the user cancels (mobile-adapt of the web drag&drop). GPX has no
 * universal mime type across platforms (Android often reports a `.gpx` as
 * `application/octet-stream`), so we pass the known GPX/XML types plus
 * octet-stream to narrow the picker without hiding real `.gpx` files; the backend
 * still validates extension/content. A picker rejection (denied permission, or a
 * second pick launched while one is in flight — rejected on some platforms) is
 * treated as a cancel (null), never a thrown rejection.
 */
export async function pickGpxFile(): Promise<GpxFile | null> {
  try {
    const result = await DocumentPicker.getDocumentAsync({
      type: [
        'application/gpx+xml',
        'application/xml',
        'text/xml',
        'application/octet-stream',
      ],
      copyToCacheDirectory: true,
      multiple: false,
    });
    if (result.canceled || !result.assets?.[0]) {
      return null;
    }
    const asset = result.assets[0];
    return { uri: asset.uri, name: asset.name, mimeType: asset.mimeType };
  } catch {
    return null;
  }
}

/**
 * Create a trip from a picked GPX file. Blocked while offline (like every other
 * mutation); a backend rejection (missing file / invalid extension = 400, empty
 * or track-less GPX = 422) is normalized to a {@link MutationFailure}. Returns
 * the new trip id, or null with the failure reported.
 */
export async function runUploadGpx(
  file: GpxFile,
  onFailure: (reason: MutationFailure) => void,
): Promise<string | null> {
  if (!useOfflineStore.getState().isOnline) {
    onFailure('offline');
    return null;
  }
  try {
    const { id, status } = await uploadGpx(file);
    if (!id) {
      onFailure(normalizeStatus(status));
      return null;
    }
    return id;
  } catch {
    onFailure('network');
    return null;
  }
}
