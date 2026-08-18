/// <reference types="jest" />
import { createElement } from 'react';
import TestRenderer, { act } from 'react-test-renderer';
import { Alert } from 'react-native';

const mockCreate = jest.fn();
const mockWrite = jest.fn();
let mockUri = 'file:///cache/exports/trip.gpx';

jest.mock('expo-file-system', () => ({
  Paths: { cache: 'file:///cache' },
  File: jest.fn().mockImplementation(() => ({
    create: mockCreate,
    write: mockWrite,
    get uri() {
      return mockUri;
    },
  })),
}));

const mockIsAvailable = jest.fn();
const mockShareAsync = jest.fn();
jest.mock('expo-sharing', () => ({
  isAvailableAsync: (...args: unknown[]) => mockIsAvailable(...args),
  shareAsync: (...args: unknown[]) => mockShareAsync(...args),
}));

jest.mock('../api/trips', () => ({
  fetchTripExport: jest.fn(),
  fetchStageExport: jest.fn(),
  tripExportFileName: jest.fn(() => 'trip.gpx'),
  stageExportFileName: jest.fn(() => 'trip-stage-1.fit'),
}));

import { fetchStageExport, fetchTripExport } from '../api/trips';
import {
  confirmExportFormat,
  runExportStage,
  runExportTrip,
  useExport,
  writeAndShare,
  type UseExport,
} from './use-export';

const mockFetchTripExport = fetchTripExport as jest.MockedFunction<typeof fetchTripExport>;
const mockFetchStageExport = fetchStageExport as jest.MockedFunction<typeof fetchStageExport>;

beforeEach(() => {
  jest.clearAllMocks();
  mockIsAvailable.mockResolvedValue(true);
  mockUri = 'file:///cache/exports/trip.gpx';
});

describe('writeAndShare (#1047)', () => {
  it('writes the bytes to a cache file and shares it with the right mime type', async () => {
    const bytes = new ArrayBuffer(4);
    await writeAndShare(bytes, 'trip.gpx', 'gpx');

    expect(mockCreate).toHaveBeenCalledWith({ intermediates: true, overwrite: true });
    expect(mockWrite).toHaveBeenCalledWith(expect.any(Uint8Array));
    expect(mockShareAsync).toHaveBeenCalledWith(mockUri, {
      mimeType: 'application/gpx+xml',
    });
  });

  it('uses the FIT mime type for a .fit export', async () => {
    await writeAndShare(new ArrayBuffer(4), 'trip.fit', 'fit');
    expect(mockShareAsync).toHaveBeenCalledWith(mockUri, {
      mimeType: 'application/vnd.ant.fit',
    });
  });

  it('throws instead of writing when no share target is available', async () => {
    mockIsAvailable.mockResolvedValue(false);
    await expect(writeAndShare(new ArrayBuffer(4), 'trip.gpx', 'gpx')).rejects.toThrow(
      'Sharing is not available on this device',
    );
    expect(mockShareAsync).not.toHaveBeenCalled();
  });
});

describe('runExportTrip (#1047)', () => {
  it('resolves true after fetching and sharing the trip file', async () => {
    mockFetchTripExport.mockResolvedValue(new ArrayBuffer(4));
    const ok = await runExportTrip('trip-1', 'My Trip', 'gpx');
    expect(mockFetchTripExport).toHaveBeenCalledWith('trip-1', 'gpx');
    expect(mockShareAsync).toHaveBeenCalled();
    expect(ok).toBe(true);
  });

  it('resolves false when the fetch fails, never throws', async () => {
    mockFetchTripExport.mockRejectedValue(new Error('network down'));
    const ok = await runExportTrip('trip-1', 'My Trip', 'gpx');
    expect(ok).toBe(false);
    expect(mockShareAsync).not.toHaveBeenCalled();
  });
});

describe('runExportStage (#1047)', () => {
  it('resolves true after fetching and sharing the stage file', async () => {
    mockFetchStageExport.mockResolvedValue(new ArrayBuffer(4));
    const ok = await runExportStage('trip-1', 1, 1, 'My Trip', 'fit');
    expect(mockFetchStageExport).toHaveBeenCalledWith('trip-1', 1, 'fit');
    expect(ok).toBe(true);
  });

  it('resolves false when the fetch fails, never throws', async () => {
    mockFetchStageExport.mockRejectedValue(new Error('network down'));
    const ok = await runExportStage('trip-1', 1, 1, 'My Trip', 'fit');
    expect(ok).toBe(false);
  });
});

describe('confirmExportFormat (#1047)', () => {
  it('offers GPX/FIT and cancel, routing the chosen format to onSelect', () => {
    const spy = jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    const onSelect = jest.fn();
    confirmExportFormat({
      title: 'Export',
      gpxLabel: 'GPX',
      fitLabel: 'FIT',
      cancelLabel: 'Cancel',
      onSelect,
    });

    expect(spy).toHaveBeenCalledTimes(1);
    const [title, , buttons] = spy.mock.calls[0] as [
      string,
      string | undefined,
      { text: string; style?: string; onPress?: () => void }[],
    ];
    expect(title).toBe('Export');
    const cancel = buttons.find((b) => b.style === 'cancel');
    const gpx = buttons.find((b) => b.text === 'GPX');
    const fit = buttons.find((b) => b.text === 'FIT');
    expect(cancel).toBeDefined();
    expect(onSelect).not.toHaveBeenCalled();
    gpx!.onPress?.();
    expect(onSelect).toHaveBeenCalledWith('gpx');
    fit!.onPress?.();
    expect(onSelect).toHaveBeenCalledWith('fit');
    spy.mockRestore();
  });
});

// Minimal renderHook on react-test-renderer (the mobile convention, no RTL).
function renderHook(onFailure: () => void): {
  result: { current: UseExport };
  unmount: () => void;
} {
  const result = { current: undefined as unknown as UseExport };
  function Probe() {
    result.current = useExport(onFailure);
    return null;
  }
  let renderer!: ReturnType<typeof TestRenderer.create>;
  act(() => {
    renderer = TestRenderer.create(createElement(Probe));
  });
  return { result, unmount: () => act(() => renderer.unmount()) };
}

describe('useExport (#1047)', () => {
  it('tracks the in-flight export and calls onFailure on error', async () => {
    mockFetchTripExport.mockRejectedValue(new Error('boom'));
    const onFailure = jest.fn();
    const { result, unmount } = renderHook(onFailure);

    expect(result.current.exporting).toBe(false);
    await act(async () => {
      await result.current.exportTrip('trip-1', 'My Trip', 'gpx');
    });
    expect(result.current.exporting).toBe(false);
    expect(onFailure).toHaveBeenCalledTimes(1);
    unmount();
  });

  it('does not call onFailure on a successful stage export', async () => {
    mockFetchStageExport.mockResolvedValue(new ArrayBuffer(4));
    const onFailure = jest.fn();
    const { result, unmount } = renderHook(onFailure);

    await act(async () => {
      await result.current.exportStage('trip-1', 1, 1, 'My Trip', 'fit');
    });
    expect(onFailure).not.toHaveBeenCalled();
    unmount();
  });
});
