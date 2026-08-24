/// <reference types="jest" />

// config.ts resolves API_BASE_URL at import time from process.env + __DEV__, so
// each case toggles those and re-imports the module in isolation (#1174).

const ORIGINAL_ENV = process.env.EXPO_PUBLIC_API_URL;
const ORIGINAL_DEV = (globalThis as { __DEV__?: boolean }).__DEV__;

function loadConfigWith(opts: { dev: boolean; apiUrl?: string }): typeof import('./config') {
  (globalThis as { __DEV__?: boolean }).__DEV__ = opts.dev;
  if (opts.apiUrl === undefined) delete process.env.EXPO_PUBLIC_API_URL;
  else process.env.EXPO_PUBLIC_API_URL = opts.apiUrl;
  // WEB_BASE_URL also fails closed in non-dev; give it a value so only the API
  // URL guard is under test.
  process.env.EXPO_PUBLIC_WEB_URL = 'https://web.example.org';
  let mod!: typeof import('./config');
  jest.isolateModules(() => {
    mod = require('./config');
  });
  return mod;
}

afterEach(() => {
  if (ORIGINAL_ENV === undefined) delete process.env.EXPO_PUBLIC_API_URL;
  else process.env.EXPO_PUBLIC_API_URL = ORIGINAL_ENV;
  (globalThis as { __DEV__?: boolean }).__DEV__ = ORIGINAL_DEV;
});

describe('API_BASE_URL https guard (#1174)', () => {
  it('rejects a non-https API URL in a non-development build', () => {
    expect(() => loadConfigWith({ dev: false, apiUrl: 'http://api.example.org' })).toThrow(
      /https/,
    );
  });

  it('accepts an https API URL in a non-development build', () => {
    const { API_BASE_URL } = loadConfigWith({ dev: false, apiUrl: 'https://api.example.org' });
    expect(API_BASE_URL).toBe('https://api.example.org');
  });

  it('allows a plaintext URL in development', () => {
    const { API_BASE_URL } = loadConfigWith({ dev: true, apiUrl: 'http://localhost:8000' });
    expect(API_BASE_URL).toBe('http://localhost:8000');
  });
});
