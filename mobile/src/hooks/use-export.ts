import { useCallback, useState } from 'react';
import { Alert } from 'react-native';
import { File, Paths } from 'expo-file-system';
import * as Sharing from 'expo-sharing';
import {
  fetchStageExport,
  fetchTripExport,
  stageExportFileName,
  tripExportFileName,
  type ExportFormat,
} from '../api/trips';

const MIME_TYPES: Record<ExportFormat, string> = {
  gpx: 'application/gpx+xml',
  fit: 'application/vnd.ant.fit',
};

// Write the fetched bytes to a cache file and hand it to the native share sheet
// (save to Files / send to another app). Extracted so the write+share plumbing is
// unit-testable without a device (mocks expo-file-system / expo-sharing).
export async function writeAndShare(
  bytes: ArrayBuffer,
  filename: string,
  format: ExportFormat,
): Promise<void> {
  const file = new File(Paths.cache, filename);
  file.create({ intermediates: true, overwrite: true });
  file.write(new Uint8Array(bytes));
  if (!(await Sharing.isAvailableAsync())) {
    throw new Error('Sharing is not available on this device');
  }
  await Sharing.shareAsync(file.uri, { mimeType: MIME_TYPES[format] });
}

// Fetch + write + share the whole trip. Never throws: resolves to false on
// failure so the caller can surface it, mirrors runDeleteTrip (#1036).
export async function runExportTrip(
  tripId: string,
  tripTitle: string,
  format: ExportFormat,
): Promise<boolean> {
  try {
    const bytes = await fetchTripExport(tripId, format);
    await writeAndShare(bytes, tripExportFileName(tripTitle, format), format);
    return true;
  } catch {
    return false;
  }
}

// Fetch + write + share a single stage (see {@link runExportTrip}).
export async function runExportStage(
  tripId: string,
  index: number,
  dayNumber: number,
  tripTitle: string,
  format: ExportFormat,
): Promise<boolean> {
  try {
    const bytes = await fetchStageExport(tripId, index, format);
    await writeAndShare(bytes, stageExportFileName(tripTitle, dayNumber, format), format);
    return true;
  } catch {
    return false;
  }
}

// Native GPX/FIT chooser: `Alert.alert` renders as platform-native buttons on
// both iOS and Android, so no bespoke sheet is needed. Extracted so the wiring is
// unit-testable, mirrors confirmDeleteTrip (#1036).
export function confirmExportFormat(opts: {
  title: string;
  gpxLabel: string;
  fitLabel: string;
  cancelLabel: string;
  onSelect: (format: ExportFormat) => void;
}): void {
  Alert.alert(opts.title, undefined, [
    { text: opts.cancelLabel, style: 'cancel' },
    { text: opts.gpxLabel, onPress: () => opts.onSelect('gpx') },
    { text: opts.fitLabel, onPress: () => opts.onSelect('fit') },
  ]);
}

export interface UseExport {
  exporting: boolean;
  exportTrip: (tripId: string, tripTitle: string, format: ExportFormat) => Promise<void>;
  exportStage: (
    tripId: string,
    index: number,
    dayNumber: number,
    tripTitle: string,
    format: ExportFormat,
  ) => Promise<void>;
}

// Tracks a single in-flight export (trip or stage) so the triggering button can
// show a spinner/disable itself; calls `onFailure` when the export could not be
// completed (network error, or no share target on the device).
export function useExport(onFailure: () => void): UseExport {
  const [exporting, setExporting] = useState(false);

  const exportTrip = useCallback(
    async (tripId: string, tripTitle: string, format: ExportFormat) => {
      setExporting(true);
      const ok = await runExportTrip(tripId, tripTitle, format);
      setExporting(false);
      if (!ok) onFailure();
    },
    [onFailure],
  );

  const exportStage = useCallback(
    async (
      tripId: string,
      index: number,
      dayNumber: number,
      tripTitle: string,
      format: ExportFormat,
    ) => {
      setExporting(true);
      const ok = await runExportStage(tripId, index, dayNumber, tripTitle, format);
      setExporting(false);
      if (!ok) onFailure();
    },
    [onFailure],
  );

  return { exporting, exportTrip, exportStage };
}
