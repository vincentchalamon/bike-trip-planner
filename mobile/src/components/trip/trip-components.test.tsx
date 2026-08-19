/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import { EMPTY_RESUPPLY } from '@btp/core';
import type { ReactElement } from 'react';
import { Text } from 'react-native';
import type { AlertData, StageData } from '@btp/core';
import i18n from '../../i18n';
import { fr } from '../../i18n/resources/fr';
import { StageCard } from './StageCard';
import { AlertsBlock } from './AlertsBlock';
import { SseStatusIndicator } from './SseStatusIndicator';
import { StageDataBlocks } from './StageDataBlocks';

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
    resupply: EMPTY_RESUPPLY,
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

describe('StageCard', () => {
  it('renders day, labels and distance/elevation meta', () => {
    const tree = render(<StageCard stage={stage()} index={0} locked={false} onDelete={jest.fn()} />);
    const t = texts(tree).join(' ');
    expect(t).toContain('Jour 1');
    expect(t).toContain('Paris');
    expect(t).toContain('Lyon');
    expect(t).toContain('50 km');
    expect(t).toContain('+100 m');
  });

  it('fires onDelete from the delete action when unlocked', () => {
    const onDelete = jest.fn();
    const tree = render(<StageCard stage={stage()} index={2} locked={false} onDelete={onDelete} />);
    act(() => {
      tree.root
        .findByProps({ accessibilityLabel: fr.trip.deleteA11y.replace('{{day}}', '1') })
        .props.onPress();
    });
    expect(onDelete).toHaveBeenCalledWith(2);
  });

  it('hides the delete action on a locked (started) trip', () => {
    const tree = render(<StageCard stage={stage()} index={0} locked onDelete={jest.fn()} />);
    expect(
      tree.root.findAllByProps({ accessibilityLabel: fr.trip.deleteA11y.replace('{{day}}', '1') }),
    ).toHaveLength(0);
  });

  it('fires onPress(index) when the summary is tapped (#1039)', () => {
    const onPress = jest.fn();
    const tree = render(
      <StageCard stage={stage()} index={3} locked={false} onDelete={jest.fn()} onPress={onPress} />,
    );
    act(() => {
      tree.root
        .findByProps({ accessibilityLabel: fr.trip.openStageA11y.replace('{{day}}', '1') })
        .props.onPress();
    });
    expect(onPress).toHaveBeenCalledWith(3);
  });

  it('routes the delete action to onDelete, never onPress (#1037)', () => {
    const onPress = jest.fn();
    const onDelete = jest.fn();
    const tree = render(
      <StageCard stage={stage()} index={2} locked={false} onDelete={onDelete} onPress={onPress} />,
    );
    act(() => {
      tree.root
        .findByProps({ accessibilityLabel: fr.trip.deleteA11y.replace('{{day}}', '1') })
        .props.onPress();
    });
    expect(onDelete).toHaveBeenCalledWith(2);
    expect(onPress).not.toHaveBeenCalled();
  });

  it('wires the tap-through to the summary only, not the data blocks (#1038)', () => {
    const onPress = jest.fn();
    const tree = render(
      <StageCard stage={stage()} index={0} locked={false} onDelete={jest.fn()} onPress={onPress} />,
    );
    // Exactly one press trigger navigates: the summary. The data blocks below
    // carry their own controls and are not wired to open the stage detail.
    const openLabel = fr.trip.openStageA11y.replace('{{day}}', '1');
    const triggers = tree.root.findAll(
      (n: any) =>
        n.props?.accessibilityLabel === openLabel && typeof n.props?.onPress === 'function',
    );
    expect(triggers).toHaveLength(1);
  });

  it('is not pressable when onPress is absent (disabled)', () => {
    const tree = render(<StageCard stage={stage()} index={0} locked={false} onDelete={jest.fn()} />);
    // No open-stage button is exposed…
    const openLabel = fr.trip.openStageA11y.replace('{{day}}', '1');
    const triggers = tree.root.findAll(
      (n: any) =>
        n.props?.accessibilityLabel === openLabel && typeof n.props?.onPress === 'function',
    );
    expect(triggers).toHaveLength(0);
    // …and the summary is rendered disabled.
    const disabled = tree.root.findAll((n: any) => n.props?.disabled === true);
    expect(disabled).toHaveLength(1);
  });
});

describe('AlertsBlock', () => {
  it('shows the empty placeholder with no alerts', () => {
    const tree = render(<AlertsBlock alerts={[]} stageKey={1} />);
    expect(texts(tree)).toContain(fr.trip.blocks.alertsEmpty);
  });

  it('renders each alert message', () => {
    const alerts: AlertData[] = [
      { type: 'warning', message: 'Gué à traverser' },
      { type: 'critical', message: 'Route principale' },
    ];
    const t = texts(render(<AlertsBlock alerts={alerts} stageKey={1} />));
    expect(t).toContain('Gué à traverser');
    expect(t).toContain('Route principale');
    expect(t).not.toContain(fr.trip.blocks.alertsEmpty);
  });
});

describe('SseStatusIndicator', () => {
  it('renders nothing when idle', () => {
    const tree = render(<SseStatusIndicator computing={false} />);
    expect(texts(tree)).not.toContain(fr.trip.sse.computing);
  });

  it('shows the "computing" badge while streaming', () => {
    const tree = render(<SseStatusIndicator computing />);
    expect(texts(tree)).toContain(fr.trip.sse.computing);
  });
});

describe('CycleNetworkBadge (via StageDataBlocks)', () => {
  const badge = (percent: string) =>
    fr.trip.blocks.cycleNetwork.replace('{{percent}}', percent);

  it('shows the badge at and above the 0.5 threshold', () => {
    expect(texts(render(<StageDataBlocks stage={stage({ onCycleNetwork: 0.5 })} />)).join(' ')).toContain(
      badge('50'),
    );
    expect(texts(render(<StageDataBlocks stage={stage({ onCycleNetwork: 0.82 })} />)).join(' ')).toContain(
      badge('82'),
    );
  });

  it('hides the badge below the threshold (0.49) and at zero', () => {
    expect(texts(render(<StageDataBlocks stage={stage({ onCycleNetwork: 0.49 })} />)).join(' ')).not.toContain(
      'Voie cyclable',
    );
    expect(texts(render(<StageDataBlocks stage={stage({ onCycleNetwork: 0 })} />)).join(' ')).not.toContain(
      'Voie cyclable',
    );
  });
});
