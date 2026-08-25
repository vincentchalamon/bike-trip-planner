/// <reference types="jest" />
import i18n from '../../i18n';
import { poiCategoryLabel } from './poi-category';

beforeAll(async () => {
  await i18n.changeLanguage('fr');
});

describe('poiCategoryLabel (#1196)', () => {
  it('localizes known food/water categories (never the raw enum)', () => {
    expect(poiCategoryLabel(i18n.t, 'bakery')).toBe('Boulangerie');
    expect(poiCategoryLabel(i18n.t, 'supermarket')).toBe('Supermarché');
    expect(poiCategoryLabel(i18n.t, 'drinking_water')).toBe('Eau potable');
    expect(poiCategoryLabel(i18n.t, 'fast_food')).toBe('Restauration rapide');
    // Full ScanPoisHandler::RESUPPLY_CATEGORIES coverage (#1196 review).
    expect(poiCategoryLabel(i18n.t, 'bar')).toBe('Bar');
    expect(poiCategoryLabel(i18n.t, 'pastry')).toBe('Pâtisserie');
    expect(poiCategoryLabel(i18n.t, 'general')).toBe('Épicerie générale');
  });

  it('humanizes an unknown category instead of showing the raw underscore enum', () => {
    expect(poiCategoryLabel(i18n.t, 'some_new_thing')).toBe('Some new thing');
  });
});
