/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import type { ReactElement } from 'react';
import { Text } from 'react-native';
import type {
  AccommodationData,
  AlertData,
  EventData,
  PoiData,
  StageData,
  SupplyMarkerData,
  WeatherData,
} from '@btp/core';
import { EMPTY_RESUPPLY } from '@btp/core';
import {
  ACCOMMODATION_RADIUS_STEP_KM,
  MAX_ACCOMMODATION_RADIUS_KM,
} from '@btp/core/constants';
import i18n from '../../i18n';
import { fr } from '../../i18n/resources/fr';
import { useDismissedAlerts } from '../../store/dismissed-alerts';
import { alertDismissKey } from './alert-utils';
import { AlertsBlock } from './AlertsBlock';
import { WeatherBlock } from './WeatherBlock';
import { AccommodationBlock } from './AccommodationBlock';
import { ResupplyBlock } from './ResupplyBlock';
import { SupplyBlock } from './SupplyBlock';
import { EventsBlock } from './EventsBlock';
import { StageDataBlocks } from './StageDataBlocks';
import { useOfflineStore } from '../../store/offline-store';
import { useTripStore } from '../../store/trip-store';

// StageDataBlocks pulls a mutations bag from this hook; a Proxy of jest.fns keeps
// every method callable without wiring the real API.
jest.mock('../../hooks/use-trip-mutations', () => ({
  useTripMutations: () => new Proxy({}, { get: () => jest.fn() }),
}));

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

function alert(overrides: Partial<AlertData> = {}): AlertData {
  return {
    type: 'warning',
    code: 'c1',
    message: 'Message',
    ...overrides,
  } as AlertData;
}

beforeAll(async () => {
  await i18n.changeLanguage('fr');
});

beforeEach(() => {
  act(() => {
    useDismissedAlerts.getState().reset();
  });
});

afterEach(() => {
  act(() => {
    while (rendered.length) rendered.pop()!.unmount();
  });
});

describe('AlertsBlock', () => {
  it('dedups two alerts of the same code to a single row', () => {
    const t = texts(
      render(
        <AlertsBlock
          alerts={[alert({ message: 'Premier' }), alert({ message: 'Second' })]}
          stageKey={1}
        />,
      ),
    );
    expect(t).toContain('Premier');
    expect(t).not.toContain('Second');
  });

  it('renders severity buckets in order (critical before nudge)', () => {
    const t = texts(
      render(
        <AlertsBlock
          alerts={[
            alert({ type: 'nudge', code: 'n', message: 'Nudge' }),
            alert({ type: 'critical', code: 'c', message: 'Critique' }),
          ]}
          stageKey={1}
        />,
      ),
    );
    expect(t.indexOf('Critique')).toBeLessThan(t.indexOf('Nudge'));
  });

  it('hides an alert dismissed on its stage (keyed on code, not wording)', () => {
    useDismissedAlerts.getState().dismiss(alertDismissKey(1, alert()));
    const t = texts(
      render(<AlertsBlock alerts={[alert({ message: 'Caché' })]} stageKey={1} />),
    );
    expect(t).not.toContain('Caché');
    expect(t).toContain(fr.trip.blocks.alertsEmpty);
  });

  it('keeps an alert dismissed on another stage (dismissal is per stage)', () => {
    // Same code 'c1' dismissed on stage 1 must remain visible on stage 2.
    useDismissedAlerts.getState().dismiss(alertDismissKey(1, alert()));
    const t = texts(
      render(<AlertsBlock alerts={[alert({ message: 'Visible' })]} stageKey={2} />),
    );
    expect(t).toContain('Visible');
  });

  it('dismisses via the dismiss action button and hides the alert on that stage only', () => {
    const a = alert({
      code: 'dz',
      message: 'À ignorer',
      action: { kind: 'dismiss', label: 'Ignorer', payload: {} },
    });
    const tree = render(<AlertsBlock alerts={[a]} stageKey={3} />);
    expect(texts(tree)).toContain('À ignorer');
    act(() => {
      tree.root
        .findByProps({ accessibilityLabel: fr.trip.blocks.alertDismiss })
        .props.onPress();
    });
    expect(
      useDismissedAlerts.getState().isDismissed(alertDismissKey(3, a)),
    ).toBe(true);
    expect(
      useDismissedAlerts.getState().isDismissed(alertDismissKey(4, a)),
    ).toBe(false);
  });

  it('routes a navigate action to onNavigate with the segment geometry', () => {
    const onNavigate = jest.fn();
    const a = alert({
      code: 'nav',
      message: 'Route principale',
      action: {
        kind: 'navigate',
        label: 'Carte',
        payload: { segments: [[[45, 4]]] },
      },
    });
    const tree = render(
      <AlertsBlock alerts={[a]} stageKey={1} onNavigate={onNavigate} />,
    );
    act(() => {
      tree.root
        .findByProps({ accessibilityLabel: fr.trip.blocks.alertNavigate })
        .props.onPress();
    });
    expect(onNavigate).toHaveBeenCalledWith([[[45, 4]]]);
  });
});

describe('WeatherBlock', () => {
  it('renders description, wind and precipitation', () => {
    const weather: WeatherData = {
      icon: 'rain',
      description: 'Averses',
      tempMin: 8,
      tempMax: 17,
      windSpeed: 21,
      windDirection: 'NO',
      precipitationProbability: 40,
      humidity: 60,
      comfortIndex: 80,
      relativeWindDirection: 'headwind',
    };
    const t = texts(render(<WeatherBlock weather={weather} />)).join(' ');
    expect(t).toContain('Averses');
    expect(t).toContain('21');
    expect(t).toContain('NO');
    expect(t).toContain('40');
  });
});

describe('AccommodationBlock', () => {
  function acc(overrides: Partial<AccommodationData> = {}): AccommodationData {
    return {
      name: 'Camping',
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

  it('shows only the selected accommodation with its badge', () => {
    const t = texts(
      render(
        <AccommodationBlock
          accommodations={[acc({ name: 'Autre' })]}
          selectedAccommodation={acc({ name: 'Choisi' })}
        />,
      ),
    );
    expect(t).toContain('Choisi');
    expect(t).not.toContain('Autre');
    expect(t).toContain(fr.trip.blocks.accommodationSelected);
  });

  it('shows the max (upper bound) for an exact price, not the min (mirrors web formatPrice)', () => {
    // Web `formatPrice` returns `fmt.format(max)` on the exact branch.
    const meta = texts(
      render(
        <AccommodationBlock
          accommodations={[
            acc({ estimatedPriceMin: 30, estimatedPriceMax: 45, isExactPrice: true }),
          ]}
        />,
      ),
    ).join(' ');
    expect(meta).toContain('45 €');
    expect(meta).not.toContain('30 €');
  });

  it('shows the min–max range for an estimated (non-exact) price', () => {
    const meta = texts(
      render(
        <AccommodationBlock
          accommodations={[
            acc({ estimatedPriceMin: 12, estimatedPriceMax: 20, isExactPrice: false }),
          ]}
        />,
      ),
    ).join(' ');
    expect(meta).toContain('12–20 €');
  });

  it('omits the price when both bounds are zero', () => {
    const meta = texts(
      render(
        <AccommodationBlock
          accommodations={[acc({ estimatedPriceMin: 0, estimatedPriceMax: 0 })]}
        />,
      ),
    ).join(' ');
    expect(meta).not.toContain('€');
  });

  it('orders candidates by proximity to the arrival (closest first)', () => {
    const t = texts(
      render(
        <AccommodationBlock
          accommodations={[
            acc({ name: 'Loin', distanceToEndPoint: 10 }),
            acc({ name: 'Proche', distanceToEndPoint: 1 }),
          ]}
        />,
      ),
    );
    expect(t.indexOf('Proche')).toBeLessThan(t.indexOf('Loin'));
  });

  it('paginates 5 at a time and reveals the rest via "show more"', () => {
    const many = Array.from({ length: 7 }, (_, i) =>
      acc({ name: `H${i}`, distanceToEndPoint: i }),
    );
    const tree = render(<AccommodationBlock accommodations={many} />);
    // H0..H4 shown; H5/H6 hidden behind the pager.
    expect(texts(tree)).toContain('H4');
    expect(texts(tree)).not.toContain('H5');
    act(() =>
      tree.root
        .findByProps({
          label: fr.trip.blocks.accommodationMore.replace('{{count}}', '5'),
        })
        .props.onPress(),
    );
    expect(texts(tree)).toContain('H6');
  });

  it('selects by the original index after sorting, not the display position', () => {
    const onSelect = jest.fn();
    const tree = render(
      <AccommodationBlock
        accommodations={[
          acc({ name: 'Loin', distanceToEndPoint: 10 }),
          acc({ name: 'Proche', distanceToEndPoint: 1 }),
        ]}
        radiusKm={5}
        onSelect={onSelect}
      />,
    );
    // "Proche" renders first (index 0) but is index 1 in the source array.
    act(() =>
      tree.root
        .findAllByProps({ label: fr.trip.blocks.accommodationSelect })[0]!
        .props.onPress(),
    );
    expect(onSelect).toHaveBeenCalledWith(1);
  });

  it('renders a zero distance rather than dropping it (accommodation at the endpoint)', () => {
    const meta = texts(
      render(<AccommodationBlock accommodations={[acc({ distanceToEndPoint: 0 })]} />),
    ).join(' ');
    expect(meta).toContain(fr.trip.blocks.distanceKm.replace('{{distance}}', '0'));
  });

  it('stays read-only (no select/expand buttons) without editing callbacks', () => {
    const tree = render(<AccommodationBlock accommodations={[acc()]} />);
    expect(
      tree.root.findAllByProps({ label: fr.trip.blocks.accommodationSelect }),
    ).toHaveLength(0);
  });

  it('selects a candidate by index via the select button', () => {
    const onSelect = jest.fn();
    const tree = render(
      <AccommodationBlock
        accommodations={[acc({ name: 'A' }), acc({ name: 'B' })]}
        radiusKm={5}
        onSelect={onSelect}
      />,
    );
    const buttons = tree.root.findAllByProps({
      label: fr.trip.blocks.accommodationSelect,
    });
    expect(buttons).toHaveLength(2);
    act(() => buttons[1]!.props.onPress());
    expect(onSelect).toHaveBeenCalledWith(1);
  });

  it('clears the selection via the deselect button', () => {
    const onDeselect = jest.fn();
    const tree = render(
      <AccommodationBlock
        accommodations={[acc()]}
        selectedAccommodation={acc({ name: 'Choisi' })}
        radiusKm={5}
        onSelect={jest.fn()}
        onDeselect={onDeselect}
      />,
    );
    act(() =>
      tree.root
        .findByProps({ label: fr.trip.blocks.accommodationDeselect })
        .props.onPress(),
    );
    expect(onDeselect).toHaveBeenCalled();
  });

  it('widens the radius while below the cap, hiding the button once reached', () => {
    const onExpandRadius = jest.fn();
    const label = fr.trip.blocks.accommodationExpandRadius.replace(
      '{{step}}',
      String(ACCOMMODATION_RADIUS_STEP_KM),
    );
    const below = render(
      <AccommodationBlock
        accommodations={[acc()]}
        radiusKm={5}
        onSelect={jest.fn()}
        onExpandRadius={onExpandRadius}
      />,
    );
    act(() => below.root.findByProps({ label }).props.onPress());
    expect(onExpandRadius).toHaveBeenCalled();

    const atCap = render(
      <AccommodationBlock
        accommodations={[acc()]}
        radiusKm={MAX_ACCOMMODATION_RADIUS_KM}
        onSelect={jest.fn()}
        onExpandRadius={onExpandRadius}
      />,
    );
    expect(atCap.root.findAllByProps({ label })).toHaveLength(0);
  });

  it('gates widen on the NEXT radius, hiding it when a step would exceed the cap', () => {
    const label = fr.trip.blocks.accommodationExpandRadius.replace(
      '{{step}}',
      String(ACCOMMODATION_RADIUS_STEP_KM),
    );
    // One step short of the cap → the next scan lands exactly on it: still shown.
    const oneStepBelow = render(
      <AccommodationBlock
        accommodations={[acc()]}
        radiusKm={MAX_ACCOMMODATION_RADIUS_KM - ACCOMMODATION_RADIUS_STEP_KM}
        onSelect={jest.fn()}
        onExpandRadius={jest.fn()}
      />,
    );
    expect(oneStepBelow.root.findAllByProps({ label })).toHaveLength(1);

    // Below the cap but a step would overshoot it → hidden (radiusKm < MAX alone
    // would wrongly still show it).
    const wouldOvershoot = render(
      <AccommodationBlock
        accommodations={[acc()]}
        radiusKm={MAX_ACCOMMODATION_RADIUS_KM - 1}
        onSelect={jest.fn()}
        onExpandRadius={jest.fn()}
      />,
    );
    expect(wouldOvershoot.root.findAllByProps({ label })).toHaveLength(0);
  });

  it('disables the select button when locked/offline', () => {
    const tree = render(
      <AccommodationBlock
        accommodations={[acc()]}
        radiusKm={5}
        disabled
        onSelect={jest.fn()}
      />,
    );
    expect(
      tree.root.findByProps({ label: fr.trip.blocks.accommodationSelect }).props
        .disabled,
    ).toBe(true);
  });

  it('blocks selection out of zone: buttons disabled + hint shown', () => {
    const tree = render(
      <AccommodationBlock
        accommodations={[acc()]}
        radiusKm={5}
        outOfZone
        onSelect={jest.fn()}
      />,
    );
    expect(
      tree.root.findByProps({ label: fr.trip.blocks.accommodationSelect }).props
        .disabled,
    ).toBe(true);
    expect(texts(tree).join(' ')).toContain(
      fr.trip.blocks.accommodationOutOfZone,
    );
  });

  it('disables the deselect button out of zone (deselect reroutes)', () => {
    const tree = render(
      <AccommodationBlock
        accommodations={[acc()]}
        selectedAccommodation={acc()}
        radiusKm={5}
        outOfZone
        onSelect={jest.fn()}
        onDeselect={jest.fn()}
      />,
    );
    expect(
      tree.root.findByProps({ label: fr.trip.blocks.accommodationDeselect })
        .props.disabled,
    ).toBe(true);
  });

  it('renders a manual accommodation like a scanned one: source label + address', () => {
    const t = texts(
      render(
        <AccommodationBlock
          accommodations={[
            acc({
              name: 'HomeExchange',
              source: 'manual',
              type: 'other',
              address: '10 rue de la Paix, Paris',
            }),
          ]}
        />,
      ),
    ).join(' ');
    expect(t).toContain('HomeExchange');
    expect(t).toContain(fr.trip.blocks.accommodationSourceManual);
    expect(t).toContain('10 rue de la Paix, Paris');
    // The manual entry must not be mislabelled as DataTourisme.
    expect(t).not.toContain('DataTourisme');
  });

  it('opens the manual form and submits the trimmed input', async () => {
    const onAddManual = jest.fn().mockResolvedValue(true);
    const tree = render(
      <AccommodationBlock
        accommodations={[]}
        radiusKm={5}
        onSelect={jest.fn()}
        onAddManual={onAddManual}
      />,
    );
    // The add button opens the form.
    act(() =>
      tree.root
        .findByProps({ label: fr.trip.blocks.accommodationAddManual })
        .props.onPress(),
    );
    // Fill title + address via their placeholders.
    act(() =>
      tree.root
        .findByProps({
          placeholder: fr.trip.blocks.accommodationManualNamePlaceholder,
        })
        .props.onChangeText('  Chez Test  '),
    );
    act(() =>
      tree.root
        .findByProps({
          placeholder: fr.trip.blocks.accommodationManualAddressPlaceholder,
        })
        .props.onChangeText(' 10 rue de la Paix '),
    );
    await act(async () => {
      await tree.root
        .findByProps({ label: fr.trip.blocks.accommodationManualSave })
        .props.onPress();
    });
    expect(onAddManual).toHaveBeenCalledWith({
      name: 'Chez Test',
      address: '10 rue de la Paix',
      priceTotal: null,
      url: null,
    });
  });

  it('drops a negative manual price to null instead of forwarding an invalid 422', async () => {
    const onAddManual = jest.fn().mockResolvedValue(true);
    const tree = render(
      <AccommodationBlock
        accommodations={[]}
        radiusKm={5}
        onSelect={jest.fn()}
        onAddManual={onAddManual}
      />,
    );
    act(() =>
      tree.root
        .findByProps({ label: fr.trip.blocks.accommodationAddManual })
        .props.onPress(),
    );
    act(() =>
      tree.root
        .findByProps({
          placeholder: fr.trip.blocks.accommodationManualNamePlaceholder,
        })
        .props.onChangeText('Chez Test'),
    );
    act(() =>
      tree.root
        .findByProps({
          placeholder: fr.trip.blocks.accommodationManualAddressPlaceholder,
        })
        .props.onChangeText('10 rue de la Paix'),
    );
    act(() =>
      tree.root
        .findByProps({
          placeholder: fr.trip.blocks.accommodationManualPricePlaceholder,
        })
        .props.onChangeText('-50'),
    );
    await act(async () => {
      await tree.root
        .findByProps({ label: fr.trip.blocks.accommodationManualSave })
        .props.onPress();
    });
    expect(onAddManual).toHaveBeenCalledWith(
      expect.objectContaining({ priceTotal: null }),
    );
  });

  it('keeps the manual save disabled until title and address are set', () => {
    const tree = render(
      <AccommodationBlock
        accommodations={[]}
        radiusKm={5}
        onSelect={jest.fn()}
        onAddManual={jest.fn().mockResolvedValue(true)}
      />,
    );
    act(() =>
      tree.root
        .findByProps({ label: fr.trip.blocks.accommodationAddManual })
        .props.onPress(),
    );
    expect(
      tree.root.findByProps({ label: fr.trip.blocks.accommodationManualSave })
        .props.disabled,
    ).toBe(true);
  });
});

describe('ResupplyBlock', () => {
  function poi(overrides: Partial<PoiData> = {}): PoiData {
    return {
      name: 'Boulangerie',
      category: 'bakery',
      lat: 0,
      lon: 0,
      distanceFromStart: 5,
      ...overrides,
    } as PoiData;
  }

  const empty = {
    foodAtLunch: [],
    waterMorning: null,
    waterAfternoon: null,
    foodAtArrival: [],
  };

  it('renders each role section with its POIs (name, category, distance)', () => {
    const t = texts(
      render(
        <ResupplyBlock
          resupply={{
            ...empty,
            foodAtLunch: [poi()],
            waterMorning: poi({ name: 'Fontaine', category: 'drinking_water' }),
          }}
        />,
      ),
    ).join(' ');
    expect(t).toContain(fr.trip.blocks.resupplyLunch);
    expect(t).toContain('Boulangerie');
    expect(t).toContain('bakery');
    expect(t).toContain(fr.trip.blocks.distanceKm.replace('{{distance}}', '5'));
    expect(t).toContain(fr.trip.blocks.resupplyWaterMorning);
    expect(t).toContain('Fontaine');
  });

  it('hides a role section that has no POI', () => {
    const t = texts(
      render(<ResupplyBlock resupply={{ ...empty, foodAtLunch: [poi()] }} />),
    ).join(' ');
    expect(t).toContain(fr.trip.blocks.resupplyLunch);
    expect(t).not.toContain(fr.trip.blocks.resupplyWaterMorning);
    expect(t).not.toContain(fr.trip.blocks.resupplyArrival);
  });

  it('shows the "suggestions only" help when non-empty', () => {
    const t = texts(
      render(<ResupplyBlock resupply={{ ...empty, foodAtArrival: [poi()] }} />),
    ).join(' ');
    expect(t).toContain(fr.trip.blocks.resupplyHelp);
  });

  it('shows the empty state (and no help) when every role is empty', () => {
    const t = texts(render(<ResupplyBlock resupply={empty} />)).join(' ');
    expect(t).toContain(fr.trip.blocks.resupplyEmpty);
    expect(t).not.toContain(fr.trip.blocks.resupplyHelp);
  });
});

describe('SupplyBlock', () => {
  it('renders markers ordered by distance with the km mark', () => {
    const markers: SupplyMarkerData[] = [
      { type: 'water', distanceFromStart: 30, lat: 0, lon: 0, water: [], food: [] },
      { type: 'food', distanceFromStart: 10, lat: 0, lon: 0, water: [], food: [] },
    ];
    const t = texts(render(<SupplyBlock supplyTimeline={markers} />));
    const first = t.find((s) => s.startsWith('km'));
    expect(first).toContain('km 10');
  });
});

describe('EventsBlock', () => {
  function event(name: string, startDate: string): EventData {
    return {
      name,
      type: 'schema:Festival',
      lat: 0,
      lon: 0,
      startDate,
      endDate: startDate,
      distanceToEndPoint: 0,
      source: 'datatourisme',
    } as EventData;
  }

  it('collapses to the first three and reveals the rest on "see more"', () => {
    const events = [
      event('A', '2026-06-01'),
      event('B', '2026-06-02'),
      event('C', '2026-06-03'),
      event('D', '2026-06-04'),
    ];
    const tree = render(<EventsBlock events={events} />);
    expect(texts(tree)).not.toContain('D');
    // The only button in the block is the "see more" toggle.
    act(() => tree.root.findByProps({ accessibilityRole: 'button' }).props.onPress());
    expect(texts(tree)).toContain('D');
  });

  it('caps the list at the soonest 5, even fully expanded', () => {
    const events = Array.from({ length: 7 }, (_, i) =>
      event(`E${i}`, `2026-06-0${i + 1}`),
    );
    const tree = render(<EventsBlock events={events} />);
    act(() => tree.root.findByProps({ accessibilityRole: 'button' }).props.onPress());
    const t = texts(tree);
    expect(t).toContain('E4'); // 5th soonest is shown
    expect(t).not.toContain('E5'); // 6th and 7th are capped out
    expect(t).not.toContain('E6');
  });
});

describe('StageDataBlocks disabled gating (#1166)', () => {
  function stageData(): StageData {
    const p = { lat: 0, lon: 0, ele: 0 };
    return {
      dayNumber: 1,
      distance: 50,
      elevation: 100,
      elevationLoss: 0,
      startPoint: p,
      endPoint: p,
      geometry: [],
      label: null,
      startLabel: 'A',
      endLabel: 'B',
      weather: null,
      alerts: [],
      resupply: EMPTY_RESUPPLY,
      accommodations: [],
      selectedAccommodation: null,
      accommodationSearchRadiusKm: 10,
      isRestDay: false,
      supplyTimeline: [],
      events: [],
    } as StageData;
  }

  const disabledOf = (): boolean => {
    const tree = render(<StageDataBlocks stage={stageData()} stageIndex={0} />);
    return tree.root.findByType(AccommodationBlock).props.disabled === true;
  };

  beforeEach(() => {
    useTripStore.setState({ tripId: 't1', isLocked: false, outOfZone: false });
    useOfflineStore.setState({ isOnline: true, apiReachable: true });
  });
  afterEach(() => {
    useOfflineStore.setState({ isOnline: true, apiReachable: true });
    useTripStore.getState().reset();
  });

  it('enables accommodation edits when online and the API is reachable', () => {
    expect(disabledOf()).toBe(false);
  });

  it('disables accommodation edits when offline', () => {
    useOfflineStore.setState({ isOnline: false });
    expect(disabledOf()).toBe(true);
  });

  it('disables accommodation edits when the API is unreachable while online (#1166)', () => {
    useOfflineStore.setState({ apiReachable: false });
    expect(disabledOf()).toBe(true);
  });
});
