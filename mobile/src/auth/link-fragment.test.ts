/// <reference types="jest" />
import { fragmentOf } from './link-fragment';

jest.mock('expo-linking', () => ({ useLinkingURL: () => null }));
jest.mock('expo-router', () => ({ useLocalSearchParams: () => ({}) }));

describe('fragmentOf', () => {
  it('reads the token after the # of an App Link', () => {
    expect(fragmentOf('https://example.test/auth/verify#tok-123')).toBe('tok-123');
  });

  it('reads it from a custom-scheme link too', () => {
    expect(fragmentOf('biketripplanner://auth/verify#tok-123')).toBe('tok-123');
  });

  it('is empty without a fragment or a URL', () => {
    expect(fragmentOf('https://example.test/auth/verify')).toBe('');
    expect(fragmentOf(null)).toBe('');
  });
});
