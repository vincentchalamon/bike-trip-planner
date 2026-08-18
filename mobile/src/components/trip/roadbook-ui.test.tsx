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

function pressableByLabel(tree: any, label: string): any {
  return tree.root.find(
    (node: any) =>
      node.props.accessibilityLabel === label &&
      typeof node.props.onPress === 'function',
  );
}

function queryByLabel(tree: any, label: string): any {
  const found = tree.root.findAll(
    (node: any) => node.props.accessibilityLabel === label,
  );
  return found[0] ?? null;
}

describe('StageCard inline edit controls (#1044)', () => {
  const handlers = () => ({
    onAddStage: jest.fn(),
    onAddRestDay: jest.fn(),
    onEditDistance: jest.fn(),
  });

  it('hides every edit control (including delete) when locked', () => {
    const h = handlers();
    const tree = render(
      <StageCard stage={stage()} index={0} locked onDelete={jest.fn()} {...h} />,
    );
    expect(queryByLabel(tree, fr.trip.deleteA11y.replace('{{day}}', '1'))).toBeNull();
    expect(
      queryByLabel(tree, fr.trip.edit.addStageA11y.replace('{{day}}', '1')),
    ).toBeNull();
    expect(
      queryByLabel(tree, fr.trip.edit.addRestDayA11y.replace('{{day}}', '1')),
    ).toBeNull();
  });

  it('calls onAddStage / onAddRestDay with the row index when tapped', () => {
    const h = handlers();
    const tree = render(
      <StageCard stage={stage()} index={2} locked={false} onDelete={jest.fn()} {...h} />,
    );
    act(() =>
      pressableByLabel(tree, fr.trip.edit.addStageA11y.replace('{{day}}', '1')).props.onPress(),
    );
    act(() =>
      pressableByLabel(tree, fr.trip.edit.addRestDayA11y.replace('{{day}}', '1')).props.onPress(),
    );
    expect(h.onAddStage).toHaveBeenCalledWith(2);
    expect(h.onAddRestDay).toHaveBeenCalledWith(2);
  });

  it('disables ＋étape and hides the distance chip out of zone, keeps ＋repos', () => {
    const h = handlers();
    const tree = render(
      <StageCard stage={stage()} index={0} locked={false} outOfZone onDelete={jest.fn()} {...h} />,
    );
    const addStage = queryByLabel(tree, fr.trip.edit.addStageA11y.replace('{{day}}', '1'));
    expect(addStage.props.accessibilityState.disabled).toBe(true);
    expect(
      queryByLabel(tree, fr.trip.edit.editDistanceA11y.replace('{{day}}', '1')),
    ).toBeNull();
    expect(
      queryByLabel(tree, fr.trip.edit.addRestDayA11y.replace('{{day}}', '1')),
    ).not.toBeNull();
  });

  it('hides the distance chip on a rest day', () => {
    const h = handlers();
    const tree = render(
      <StageCard
        stage={stage({ isRestDay: true })}
        index={0}
        locked={false}
        onDelete={jest.fn()}
        {...h}
      />,
    );
    expect(
      queryByLabel(tree, fr.trip.edit.editDistanceA11y.replace('{{day}}', '1')),
    ).toBeNull();
  });

  it('edits distance inline: pencil → input → save calls onEditDistance(km)', () => {
    const h = handlers();
    const tree = render(
      <StageCard
        stage={stage({ distance: 50 })}
        index={0}
        locked={false}
        onDelete={jest.fn()}
        {...h}
      />,
    );
    const distA11y = fr.trip.edit.editDistanceA11y.replace('{{day}}', '1');
    act(() => pressableByLabel(tree, distA11y).props.onPress());
    // The input is seeded with the current rounded distance.
    const input = queryByLabel(tree, distA11y);
    expect(input.props.value).toBe('50');
    act(() => input.props.onChangeText('72'));
    act(() => pressableByLabel(tree, fr.trip.edit.saveA11y).props.onPress());
    expect(h.onEditDistance).toHaveBeenCalledWith(0, 72);
  });

  it('keeps the editor open and does not commit an invalid or empty distance', () => {
    const h = handlers();
    const tree = render(
      <StageCard stage={stage({ distance: 50 })} index={0} locked={false} onDelete={jest.fn()} {...h} />,
    );
    const distA11y = fr.trip.edit.editDistanceA11y.replace('{{day}}', '1');
    act(() => pressableByLabel(tree, distA11y).props.onPress());
    act(() => queryByLabel(tree, distA11y).props.onChangeText('abc'));
    act(() => pressableByLabel(tree, fr.trip.edit.saveA11y).props.onPress());
    expect(h.onEditDistance).not.toHaveBeenCalled();
    // The editor stays open (the input is still mounted) so the edit is not lost.
    const input = queryByLabel(tree, distA11y);
    expect(input).not.toBeNull();
    expect('value' in input.props).toBe(true);
    expect(input.props.value).toBe('abc');
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
