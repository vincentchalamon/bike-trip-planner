/// <reference types="jest" />
import type { StageData } from '@btp/core';
import type { AccommodationData } from '@btp/core';
import {
  buildTripText,
  computeEstimatedBudget,
  computeOverallDifficulty,
  computeTripTotals,
  getDifficulty,
} from './share';

function acc(over: Partial<AccommodationData> = {}): AccommodationData {
  return {
    name: 'Gîte',
    type: 'hotel',
    lat: 0,
    lon: 0,
    estimatedPriceMin: 40,
    estimatedPriceMax: 60,
    isExactPrice: false,
    url: null,
    possibleClosed: false,
    distanceToEndPoint: 0,
    source: 'osm',
    ...over,
  } as AccommodationData;
}

function stage(over: Partial<StageData> = {}): StageData {
  return {
    dayNumber: 1,
    distance: 50,
    elevation: 300,
    elevationLoss: 200,
    startPoint: { lat: 0, lon: 0, ele: 0 },
    endPoint: { lat: 1, lon: 1, ele: 0 },
    geometry: [
      { lat: 0, lon: 0, ele: 0 },
      { lat: 1, lon: 1, ele: 10 },
    ],
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
    ...over,
  };
}

describe('computeTripTotals (#1048)', () => {
  it('sums distance, ascent and descent over every stage', () => {
    const totals = computeTripTotals([
      stage({ distance: 50, elevation: 300, elevationLoss: 200 }),
      stage({ distance: 30, elevation: 100, elevationLoss: 150 }),
    ]);
    expect(totals).toEqual({
      totalDistance: 80,
      totalElevation: 400,
      totalElevationLoss: 350,
    });
  });
});

describe('computeEstimatedBudget (#1048)', () => {
  it('adds meals per stage and accommodation, skipping the last stage lodging', () => {
    const budget = computeEstimatedBudget([
      stage({ dayNumber: 1, accommodations: [acc()] }),
      stage({ dayNumber: 2 }),
    ]);
    // food: 2 stages x 2 meals x (12,20) = (48, 80); lodging: only stage 0 -> (40, 60)
    expect(budget).toEqual({ min: 88, max: 140 });
  });

  it('counts three meals per rest day and no lodging for it', () => {
    const budget = computeEstimatedBudget([stage({ isRestDay: true })]);
    expect(budget).toEqual({ min: 36, max: 60 });
  });
});

describe('getDifficulty / computeOverallDifficulty (#1048)', () => {
  it('classifies a short flat stage as easy', () => {
    expect(getDifficulty(50, 300)).toBe('easy');
  });

  it('classifies a long climb as hard', () => {
    expect(getDifficulty(120, 2000)).toBe('hard');
  });

  it('returns the overall difficulty label and color', () => {
    const result = computeOverallDifficulty([stage()], {
      easy: 'Facile',
      medium: 'Modéré',
      hard: 'Difficile',
    });
    expect(result).toEqual({ key: 'easy', label: 'Facile', color: '#22c55e' });
  });
});

describe('buildTripText (#1048)', () => {
  const labels = {
    totalDistance: 'Distance totale',
    totalElevation: 'Dénivelé',
    viewOnline: 'Voir en ligne',
  };

  it('includes the budget per stage and appends the share link', () => {
    const text = buildTripText({
      title: 'Traversée des Alpes',
      totalDistance: 80,
      totalElevation: 400,
      totalElevationLoss: 350,
      sourceUrl: 'https://www.komoot.com/tour/1',
      stages: [
        stage({ dayNumber: 1, accommodations: [acc()] }),
        stage({ dayNumber: 2 }),
      ],
      startDate: '2026-06-01',
      shareUrl: 'https://web.example/s/abc123',
      labels,
    });

    expect(text).toContain('Traversée des Alpes');
    expect(text).toContain('Distance totale : 80km');
    expect(text).toContain('https://www.komoot.com/tour/1');
    // Last stage carries a food-only budget (2 meals): 24-40€.
    expect(text).toContain('24-40€');
    // Share link appended at the end.
    expect(text).toContain('Voir en ligne : https://web.example/s/abc123');
    expect(text.trim().endsWith('https://web.example/s/abc123')).toBe(true);
  });

  it('omits the share-link section when no link is provided', () => {
    const text = buildTripText({
      title: 'Sans lien',
      totalDistance: null,
      totalElevation: null,
      totalElevationLoss: null,
      sourceUrl: '',
      stages: [stage()],
      startDate: null,
      shareUrl: null,
      labels,
    });
    expect(text).not.toContain('Voir en ligne');
  });
});
