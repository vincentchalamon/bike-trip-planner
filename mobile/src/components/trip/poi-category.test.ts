/// <reference types="jest" />
import i18n from '../../i18n';
import { poiCategoryLabel } from './poi-category';

const t = ((key: string, opts?: object) => i18n.t(key, opts)) as never;

beforeAll(async () => {
  await i18n.changeLanguage('fr');
});

describe('poiCategoryLabel (#1196)', () => {
  it('localizes known food/water categories (never the raw enum)', () => {
    expect(poiCategoryLabel(t, 'bakery')).toBe('Boulangerie');
    expect(poiCategoryLabel(t, 'supermarket')).toBe('Supermarché');
    expect(poiCategoryLabel(t, 'drinking_water')).toBe('Eau potable');
    expect(poiCategoryLabel(t, 'fast_food')).toBe('Restauration rapide');
  });

  it('humanizes an unknown category instead of showing the raw underscore enum', () => {
    expect(poiCategoryLabel(t, 'some_new_thing')).toBe('Some new thing');
  });
});
