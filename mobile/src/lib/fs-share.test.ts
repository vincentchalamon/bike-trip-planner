/// <reference types="jest" />

// Track lifecycle calls on the temp export file so the "delete after share" fix
// (#1174) can be asserted without a device.
const fileOps: string[] = [];

jest.mock('expo-file-system', () => {
  class File {
    uri: string;
    constructor(_dir: unknown, name: string) {
      this.uri = `file:///cache/${name}`;
    }
    create() {
      fileOps.push('create');
    }
    write() {
      fileOps.push('write');
    }
    delete() {
      fileOps.push('delete');
    }
  }
  return { File, Paths: { cache: { uri: 'file:///cache' } } };
});

jest.mock('expo-sharing', () => ({
  isAvailableAsync: jest.fn(),
  shareAsync: jest.fn(),
}));

import * as Sharing from 'expo-sharing';
import { writeAndShareFile } from './fs-share';

const mockIsAvailable = Sharing.isAvailableAsync as jest.MockedFunction<
  typeof Sharing.isAvailableAsync
>;
const mockShare = Sharing.shareAsync as jest.MockedFunction<typeof Sharing.shareAsync>;

beforeEach(() => {
  fileOps.length = 0;
  jest.clearAllMocks();
});

describe('writeAndShareFile temp-file cleanup (#1174)', () => {
  it('deletes the temp export file after a successful share', async () => {
    mockIsAvailable.mockResolvedValue(true);
    mockShare.mockResolvedValue(undefined as never);

    await writeAndShareFile(new ArrayBuffer(4), 'account-export.json', 'application/json');

    expect(mockShare).toHaveBeenCalledWith('file:///cache/account-export.json', {
      mimeType: 'application/json',
    });
    // Written, then deleted once the share sheet returned.
    expect(fileOps).toEqual(['create', 'write', 'delete']);
  });

  it('still deletes the temp file when sharing throws', async () => {
    mockIsAvailable.mockResolvedValue(true);
    mockShare.mockRejectedValue(new Error('share cancelled'));

    await expect(
      writeAndShareFile(new ArrayBuffer(4), 'trip.gpx', 'application/gpx+xml'),
    ).rejects.toThrow('share cancelled');

    expect(fileOps).toContain('delete');
  });

  it('does not share (nor delete) when the platform cannot share', async () => {
    mockIsAvailable.mockResolvedValue(false);

    await expect(
      writeAndShareFile(new ArrayBuffer(4), 'trip.gpx', 'application/gpx+xml'),
    ).rejects.toThrow('Sharing is not available on this device');

    expect(mockShare).not.toHaveBeenCalled();
    expect(fileOps).not.toContain('delete');
  });
});
