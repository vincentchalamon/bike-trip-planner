/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import type { ReactElement } from 'react';
import { Text } from 'react-native';
import type { AccommodationData } from '@btp/core';
import { FILTERABLE_ACCOMMODATION_TYPES } from '@btp/core/constants';
import i18n from '../../i18n';
import { fr } from '../../i18n/resources/fr';
import { AccommodationBlock } from './AccommodationBlock';

// Localized accommodation-type label (#1170). Candidates used to show the raw
// enum (`camp_site`, `guest_house`, `other`…) instead of the same localized
// label already used in ConfigSheet (`config.type_*`).

beforeAll(async () => {
  await i18n.changeLanguage('fr');
});

function texts(node: any): string[] {
  return node.root.findAllByType(Text).flatMap((t: any) => {
    const kids = Array.isArray(t.props.children) ? t.props.children : [t.props.children];
    return kids.filter((c: unknown): c is string => typeof c === 'string');
  });
}

const rendered: any[] = [];

function render(element: ReactElement): any {
  let out: any;
  act(() => {
    out = TestRenderer.create(element);
  });
  rendered.push(out);
  return out;
}

afterEach(() => {
  rendered.splice(0).forEach((r) => act(() => r.unmount()));
});

function acc(overrides: Partial<AccommodationData> = {}): AccommodationData {
  return {
    name: 'Un hébergement',
    type: 'camp_site',
    lat: 0,
    lon: 0,
    estimatedPriceMin: 12,
    estimatedPriceMax: 20,
    isExactPrice: false,
    possibleClosed: false,
    distanceToEndPoint: 2,
    source: 'osm',
    ...overrides,
  } as AccommodationData;
}

describe('AccommodationBlock — localized type label #1170', () => {
  it('shows the localized label ("Camping") instead of the raw enum ("camp_site")', () => {
    const t = texts(
      render(
        <AccommodationBlock
          accommodations={[acc({ type: 'camp_site' })]}
          onSelect={jest.fn()}
        />,
      ),
    );
    const meta = t.join(' ');
    expect(meta).toContain(fr.config.type_camp_site);
    expect(meta).not.toContain('camp_site');
  });

  it('localizes a manual ("other") candidate as "Autre" rather than the raw enum', () => {
    const t = texts(
      render(
        <AccommodationBlock
          accommodations={[acc({ type: 'other', source: 'manual' })]}
          onSelect={jest.fn()}
        />,
      ),
    );
    const meta = t.join(' ');
    expect(meta).toContain(fr.config.type_other);
    expect(meta).not.toContain(' other ');
  });

  it('labels a type the catalog does not know as "Autre" (mirrors pwa), never the raw enum', () => {
    const t = texts(
      render(
        <AccommodationBlock
          accommodations={[acc({ type: 'unknown_future_type' })]}
          onSelect={jest.fn()}
        />,
      ),
    );
    expect(t.join(' ')).toContain(fr.config.type_other);
    expect(t.join(' ')).not.toContain('unknown_future_type');
    expect(t.join(' ')).not.toContain('unknown future type');
  });

  it('has a translation key for every known accommodation type (no silent key miss)', () => {
    // A filterable type added upstream would make isKnownAccommodationType return
    // true and call t(`config.type_${type}`); without a matching fr/en key i18next
    // prints the raw key, silently reopening #1170 for the next type. Mirrors
    // pwa/src/lib/accommodation-types.test.ts.
    for (const type of [...FILTERABLE_ACCOMMODATION_TYPES, 'other']) {
      expect(fr.config[`type_${type}` as keyof typeof fr.config]).toBeDefined();
    }
  });
});
