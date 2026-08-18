import { type RefObject } from 'react';
import { type View } from 'react-native';
import { captureRef } from 'react-native-view-shot';
import * as Sharing from 'expo-sharing';

// Snapshot the off-screen infographic View to a PNG and hand it to the native
// share sheet (#1048). The RN adaptation of the web's canvas.toDataURL +
// download: capture the rendered View instead of a canvas, then share the file.
export async function captureAndShareInfographic(
  ref: RefObject<View | null>,
  dialogTitle: string,
): Promise<string | null> {
  if (!ref.current) {
    return null;
  }
  const uri = await captureRef(ref, { format: 'png', quality: 1 });
  if (await Sharing.isAvailableAsync()) {
    await Sharing.shareAsync(uri, {
      mimeType: 'image/png',
      dialogTitle,
      UTI: 'public.png',
    });
  }
  return uri;
}
