/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import type { ReactElement } from 'react';
import { Text } from 'react-native';
import type { StageData, WeatherData } from '@btp/core';
import i18n from '../../i18n';
import { RoadbookSummary } from './RoadbookSummary';

function texts(tree: any): string[] {
  return tree.root
    .findAllByType(Text)
    .flatMap((n: any) => {
      const kids = Array.isArray(n.props.children) ? n.props.children : [n.props.children];
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
    elevation: 300,
    elevationLoss: 200,
    startPoint: point,
    endPoint: point,
    geometry: [],
    label: null,
    startLabel: null,
    endLabel: null,
    weather: null,
    alerts: [],
    pois: [],
    accommodations: [],
    selectedAccommodation: null,
    accommodationSearchRadiusKm: 5,
    isRestDay: false,
    supplyTimeline: [],
    events: [],
    ...overrides,
  };
}

beforeAll(async () => {
  await i18n.changeLanguage('fr');
});

describe('RoadbookSummary', () => {
  it('shows the "dates to define" copy when no start date', () => {
    const tree = render(<RoadbookSummary stages={[stage()]} startDate={null} endDate={null} />);
    expect(texts(tree)).toContain(i18n.t('trip.summary.noDates'));
  });

  it('adds the weather metric only when a stage carries weather', () => {
    const withWeather = render(
      <RoadbookSummary
        stages={[stage({ weather: { tempMax: 24 } as WeatherData })]}
        startDate="2026-08-15"
        endDate="2026-08-20"
      />,
    );
    expect(texts(withWeather)).toContain(i18n.t('trip.summary.weather'));

    const withoutWeather = render(
      <RoadbookSummary stages={[stage()]} startDate="2026-08-15" endDate="2026-08-20" />,
    );
    expect(texts(withoutWeather)).not.toContain(i18n.t('trip.summary.weather'));
  });
});
