/// <reference types="jest" />
import { initialsFromEmail } from './initials';

describe('initialsFromEmail', () => {
  it('takes the first letter of a single-token local part', () => {
    expect(initialsFromEmail('noe@les-tilleuls.coop')).toBe('N');
  });

  it('takes up to two tokens split on . _ -', () => {
    expect(initialsFromEmail('vincent.chalamon@example.fr')).toBe('VC');
    expect(initialsFromEmail('jean_pierre.dupont@x.io')).toBe('JP');
  });

  it('uppercases the initials', () => {
    expect(initialsFromEmail('anna@x.io')).toBe('A');
  });

  it('falls back to ? for a missing or empty email', () => {
    expect(initialsFromEmail(null)).toBe('?');
    expect(initialsFromEmail('')).toBe('?');
    expect(initialsFromEmail('@x.io')).toBe('?');
  });
});
