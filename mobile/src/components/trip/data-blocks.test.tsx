/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import type { ReactElement } from 'react';
import { Text } from 'react-native';
import type {
  AccommodationData,
  AlertData,
  EventData,
  SupplyMarkerData,
  WeatherData,
} from '@btp/core';
import i18n from '../../i18n';
import { fr } from '../../i18n/resources/fr';
import { useDismissedAlerts } from '../../store/dismissed-alerts';
import { alertDismissKey } from './alert-utils';
import { AlertsBlock } from './AlertsBlock';
import { WeatherBlock } from './WeatherBlock';
import { AccommodationBlock } from './AccommodationBlock';
import { SupplyBlock } from './SupplyBlock';
import { EventsBlock } from './EventsBlock';

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
});
