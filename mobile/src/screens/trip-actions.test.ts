/// <reference types="jest" />
import { nextTitle } from './trip-actions';

describe('nextTitle', () => {
  it('returns the trimmed title when it changed', () => {
    expect(nextTitle('  Tour du Vercors ', 'Ancien titre')).toBe('Tour du Vercors');
  });

  it('returns null for an empty or whitespace-only draft', () => {
    expect(nextTitle('', 'x')).toBeNull();
    expect(nextTitle('   ', 'x')).toBeNull();
  });

  it('returns null when the trimmed draft equals the current title', () => {
    expect(nextTitle(' Same ', 'Same')).toBeNull();
  });

  it('treats a null current title as an empty string', () => {
    expect(nextTitle('   ', null)).toBeNull();
    expect(nextTitle('New', null)).toBe('New');
  });
});
