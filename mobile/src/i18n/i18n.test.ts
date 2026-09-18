/// <reference types="jest" />
import i18n, { applyAccountLocale } from './index';
import { en } from './resources/en';
import { fr } from './resources/fr';

function flatKeys(obj: Record<string, unknown>, prefix = ''): string[] {
  return Object.entries(obj).flatMap(([k, v]) => {
    const key = prefix ? `${prefix}.${k}` : k;
    return typeof v === 'object' && v !== null
      ? flatKeys(v as Record<string, unknown>, key)
      : [key];
  });
}

describe('i18n', () => {
  it('fr and en expose the exact same key set', () => {
    expect(flatKeys(en).sort()).toEqual(flatKeys(fr).sort());
  });

  it('defaults to French and switches to English', async () => {
    await i18n.changeLanguage('fr');
    expect(i18n.t('nav.trips')).toBe('Voyages');
    await i18n.changeLanguage('en');
    expect(i18n.t('nav.trips')).toBe('Trips');
    await i18n.changeLanguage('fr');
  });

  it('interpolates the day number', () => {
    expect(i18n.t('trip.day', { day: 3 })).toContain('3');
  });
});

// The server renders a trip's alerts in the account's locale (ADR-063), so the
// interface adopts it once the session resolves -- otherwise an account set to
// English would show a French UI next to English alerts.
describe('applyAccountLocale', () => {
  afterEach(async () => {
    await i18n.changeLanguage('fr');
  });

  it('switches the interface to the account locale', async () => {
    await i18n.changeLanguage('fr');

    applyAccountLocale('en');

    expect(i18n.language).toBe('en');
  });

  it.each([[undefined], [null], ['kl'], [42]])(
    'leaves the interface alone for an unusable value (%p)',
    async (value) => {
      await i18n.changeLanguage('en');

      applyAccountLocale(value);

      expect(i18n.language).toBe('en');
    },
  );
});
