/// <reference types="jest" />
jest.mock('react-native-view-shot', () => ({ captureRef: jest.fn() }));
jest.mock('expo-sharing', () => ({
  isAvailableAsync: jest.fn(),
  shareAsync: jest.fn(),
}));

import { type View } from 'react-native';
import { captureRef } from 'react-native-view-shot';
import * as Sharing from 'expo-sharing';
import { captureAndShareInfographic } from './share-image';

const mockCapture = captureRef as jest.MockedFunction<typeof captureRef>;
const mockIsAvailable = Sharing.isAvailableAsync as jest.MockedFunction<
  typeof Sharing.isAvailableAsync
>;
const mockShare = Sharing.shareAsync as jest.MockedFunction<typeof Sharing.shareAsync>;

beforeEach(() => jest.clearAllMocks());

describe('captureAndShareInfographic (#1048)', () => {
  it('captures the ref as a PNG and hands it to the native share sheet', async () => {
    mockCapture.mockResolvedValue('file:///tmp/trip.png');
    mockIsAvailable.mockResolvedValue(true);

    const ref = { current: {} as View };
    const uri = await captureAndShareInfographic(ref, 'Alps');

    expect(mockCapture).toHaveBeenCalledWith(ref, { format: 'png', quality: 1 });
    expect(mockShare).toHaveBeenCalledWith('file:///tmp/trip.png', {
      mimeType: 'image/png',
      dialogTitle: 'Alps',
      UTI: 'public.png',
    });
    expect(uri).toBe('file:///tmp/trip.png');
  });

  it('does nothing when the ref is not mounted', async () => {
    const uri = await captureAndShareInfographic({ current: null }, 'Alps');
    expect(mockCapture).not.toHaveBeenCalled();
    expect(mockShare).not.toHaveBeenCalled();
    expect(uri).toBeNull();
  });

  it('skips sharing when the platform has no share capability', async () => {
    mockCapture.mockResolvedValue('file:///tmp/trip.png');
    mockIsAvailable.mockResolvedValue(false);

    const uri = await captureAndShareInfographic({ current: {} as View }, 'Alps');

    expect(mockShare).not.toHaveBeenCalled();
    expect(uri).toBe('file:///tmp/trip.png');
  });
});
