/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import type { ReactElement } from 'react';
import { Text } from 'react-native';
import type { StageData } from '@btp/core';
import i18n from '../../i18n';
import { fr } from '../../i18n/resources/fr';
import { StageCard } from './StageCard';
import { RoadbookBanner } from './RoadbookBanner';

function texts(node: any): string[] {
  return node.root.findAllByType(Text).flatMap((t: any) => {
    const kids = Array.isArray(t.props.children) ? t.props.children : [t.props.children];
    return kids.filter((c: unknown): c is string => typeof c === 'string');
  });
}

function render(element: ReactElement): any {
  let out: any;
  act(() => {
    out = TestRenderer.create(element);
  });
  return out;
}

function stage(overrides: Partial<StageData> = {}): StageData {
  const point = { lat: 0, lon: 0, ele: 0 };
  return {
    dayNumber: 1,
    distance: 50,
    elevation: 100,
    elevationLoss: 0,
    startPoint: point,
    endPoint: point,
    geometry: [],
    label: null,
    startLabel: 'Paris',
    endLabel: 'Lyon',
    weather: null,
    alerts: [],
    pois: [],
    accommodations: [],
    selectedAccommodation: null,
    accommodationSearchRadiusKm: 10,
    isRestDay: false,
    supplyTimeline: [],
    events: [],
    ...overrides,
  };
}

beforeAll(async () => {
  await i18n.changeLanguage('fr');
});

describe('StageCard dates', () => {
  it('falls back to "Jour N" when no date is provided', () => {
    const t = texts(render(<StageCard stage={stage()} index={0} locked={false} onDelete={jest.fn()} />));
    expect(t.join(' ')).toContain('Jour 1');
  });

  it('shows the stage date instead of the day number when provided', () => {
    const t = texts(
      render(
        <StageCard stage={stage()} index={0} locked={false} onDelete={jest.fn()} date="2026-08-13" />,
      ),
    );
    expect(t.join(' ')).toContain('13');
    expect(t.join(' ')).not.toContain('Jour 1');
  });

  it('renders the "Aujourd\'hui" pastille only when isToday', () => {
    const withBadge = texts(
      render(
        <StageCard stage={stage()} index={0} locked={false} onDelete={jest.fn()} date="2026-08-13" isToday />,
      ),
    );
    expect(withBadge).toContain(fr.trip.today);

    const withoutBadge = texts(
      render(
        <StageCard stage={stage()} index={0} locked={false} onDelete={jest.fn()} date="2026-08-13" />,
      ),
    );
    expect(withoutBadge).not.toContain(fr.trip.today);
  });
});

describe('RoadbookBanner', () => {
  it('renders the message for each variant', () => {
    expect(texts(render(<RoadbookBanner variant="locked" message={fr.trip.banners.locked} />))).toContain(
      fr.trip.banners.locked,
    );
    expect(
      texts(render(<RoadbookBanner variant="outOfZone" message={fr.trip.banners.outOfZone} />)),
    ).toContain(fr.trip.banners.outOfZone);
    expect(texts(render(<RoadbookBanner variant="noDates" message={fr.trip.banners.noDates} />))).toContain(
      fr.trip.banners.noDates,
    );
  });
});
