/// <reference types="jest" />
import { createElement } from 'react';
import TestRenderer, { act } from 'react-test-renderer';

const mockCreate = jest.fn();
const mockWrite = jest.fn();

jest.mock('expo-file-system', () => ({
  Paths: { cache: 'file:///cache' },
  File: jest.fn().mockImplementation(() => ({
    create: mockCreate,
    write: mockWrite,
    get uri() {
      return 'file:///cache/bike-trip-planner-data.json';
    },
  })),
}));

const mockIsAvailable = jest.fn();
const mockShareAsync = jest.fn();
jest.mock('expo-sharing', () => ({
  isAvailableAsync: (...args: unknown[]) => mockIsAvailable(...args),
  shareAsync: (...args: unknown[]) => mockShareAsync(...args),
}));

jest.mock('../api/account', () => ({
  ACCOUNT_EXPORT_FILENAME: 'bike-trip-planner-data.json',
  fetchAccountExport: jest.fn(),
}));

import { fetchAccountExport } from '../api/account';
import { runAccountExport, useAccountExport, type UseAccountExport } from './use-account-export';

const mockFetch = fetchAccountExport as jest.MockedFunction<typeof fetchAccountExport>;

beforeEach(() => {
  jest.clearAllMocks();
  mockIsAvailable.mockResolvedValue(true);
  mockWrite.mockResolvedValue(undefined);
  mockShareAsync.mockResolvedValue(undefined);
  mockFetch.mockResolvedValue(new ArrayBuffer(8));
});

describe('runAccountExport', () => {
  it('fetches, writes and shares the archive', async () => {
    const ok = await runAccountExport();
    expect(ok).toBe(true);
    expect(mockFetch).toHaveBeenCalledTimes(1);
    expect(mockWrite).toHaveBeenCalledTimes(1);
    expect(mockShareAsync).toHaveBeenCalledWith('file:///cache/bike-trip-planner-data.json', {
      mimeType: 'application/json',
    });
  });

  it('resolves false when the fetch fails', async () => {
    mockFetch.mockRejectedValue(new Error('network'));
    expect(await runAccountExport()).toBe(false);
    expect(mockShareAsync).not.toHaveBeenCalled();
  });

  it('resolves false when sharing is unavailable', async () => {
    mockIsAvailable.mockResolvedValue(false);
    expect(await runAccountExport()).toBe(false);
    expect(mockShareAsync).not.toHaveBeenCalled();
  });
});

describe('useAccountExport', () => {
  function harness(onFailure: () => void): { current: UseAccountExport } {
    const ref: { current: UseAccountExport } = { current: null as unknown as UseAccountExport };
    function Probe() {
      ref.current = useAccountExport(onFailure);
      return null;
    }
    act(() => {
      TestRenderer.create(createElement(Probe));
    });
    return ref;
  }

  it('calls onFailure and resolves false when the export fails', async () => {
    mockFetch.mockRejectedValue(new Error('boom'));
    const onFailure = jest.fn();
    const hook = harness(onFailure);
    let result: boolean | undefined;
    await act(async () => {
      result = await hook.current.exportAccount();
    });
    expect(result).toBe(false);
    expect(onFailure).toHaveBeenCalledTimes(1);
    expect(hook.current.exporting).toBe(false);
  });

  it('resolves true and does not call onFailure on success', async () => {
    const onFailure = jest.fn();
    const hook = harness(onFailure);
    let result: boolean | undefined;
    await act(async () => {
      result = await hook.current.exportAccount();
    });
    expect(result).toBe(true);
    expect(onFailure).not.toHaveBeenCalled();
  });
});
