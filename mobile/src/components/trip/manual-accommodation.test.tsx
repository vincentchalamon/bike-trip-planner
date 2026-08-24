/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import type { ReactElement } from 'react';
import { Alert, Text } from 'react-native';
import type { AccommodationData, StageData } from '@btp/core';
import { EMPTY_RESUPPLY } from '@btp/core';
import i18n from '../../i18n';
import { fr } from '../../i18n/resources/fr';
import { AccommodationBlock } from './AccommodationBlock';
import { StageDataBlocks } from './StageDataBlocks';
import { useTripStore } from '../../store/trip-store';
import { useOfflineStore } from '../../store/offline-store';

// Manual (hors-app) accommodation flow — #1097. The web is covered by
// pwa/tests/mocked/manual-accommodation.spec.ts; this mirrors it for mobile
// (CI mobile = tsc + jest). Recette Sprint 58.b (#1182).

// The failure-surfacing tests below drive the real mutation runner
// (StageDataBlocks -> useTripMutations -> runAddManualAccommodation), so only
// the HTTP boundary is mocked (mirrors mobile/src/store/mutations.test.ts).
jest.mock('../../store/trip-cache', () => ({ deleteTripCache: jest.fn() }));
jest.mock('../../api/trips', () => ({
  updateTripConfig: jest.fn(),
  createStage: jest.fn(),
  updateStageDistance: jest.fn(),
  moveStage: jest.fn(),
  insertRestDay: jest.fn(),
  setStageAccommodation: jest.fn(),
  addManualAccommodation: jest.fn(),
  addPoiWaypoint: jest.fn(),
  scanAccommodations: jest.fn(),
  applyBatchRecompute: jest.fn(),
  analyzeTrip: jest.fn(),
  duplicateTrip: jest.fn(),
  deleteTrip: jest.fn(),
}));
import { addManualAccommodation } from '../../api/trips';

const mockAddManualAccommodation = addManualAccommodation as jest.MockedFunction<
  typeof addManualAccommodation
>;

// i18n init is async; make sure French is loaded before rendering (mirrors
// data-blocks.test.tsx), otherwise t() returns raw keys.
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

// Button forwards `label` onto more than one node, so match all and take the
// first (mirrors data-blocks.test.tsx).
function btn(tree: any, label: string): any {
  return tree.root.findAllByProps({ label })[0]!;
}

function hasBtn(tree: any, label: string): boolean {
  return tree.root.findAllByProps({ label }).length > 0;
}

function openForm(tree: any) {
  act(() => btn(tree, fr.trip.blocks.accommodationAddManual).props.onPress());
}

function setField(tree: any, placeholder: string, value: string) {
  act(() => tree.root.findAllByProps({ placeholder })[0]!.props.onChangeText(value));
}

const P = fr.trip.blocks;

describe('AccommodationBlock — manual (hors-app) entry #1097', () => {
  it('exposes the add-manual affordance even with an empty candidate list', () => {
    const tree = render(
      <AccommodationBlock accommodations={[]} onSelect={jest.fn()} onAddManual={jest.fn()} />,
    );
    expect(hasBtn(tree, P.accommodationAddManual)).toBe(true);
  });

  it('reveals the four-field form and submits the parsed payload', async () => {
    const onAddManual = jest.fn().mockResolvedValue(true);
    const tree = render(
      <AccommodationBlock
        accommodations={[]}
        radiusKm={5}
        onSelect={jest.fn()}
        onAddManual={onAddManual}
      />,
    );

    openForm(tree);
    // The four #1097 fields are present once the form is open.
    for (const placeholder of [
      P.accommodationManualNamePlaceholder,
      P.accommodationManualAddressPlaceholder,
      P.accommodationManualPricePlaceholder,
      P.accommodationManualUrlPlaceholder,
    ]) {
      expect(tree.root.findAllByProps({ placeholder }).length).toBeGreaterThan(0);
    }

    setField(tree, P.accommodationManualNamePlaceholder, 'Gîte Test');
    setField(tree, P.accommodationManualAddressPlaceholder, 'Grand Place, Lille');
    setField(tree, P.accommodationManualPricePlaceholder, '45');
    setField(tree, P.accommodationManualUrlPlaceholder, 'https://ex.fr');

    await act(async () => {
      await btn(tree, P.accommodationManualSave).props.onPress();
    });

    expect(onAddManual).toHaveBeenCalledWith({
      name: 'Gîte Test',
      address: 'Grand Place, Lille',
      priceTotal: 45,
      url: 'https://ex.fr',
    });
    // On success the form closes: the add-manual button is back.
    expect(hasBtn(tree, P.accommodationAddManual)).toBe(true);
  });

  it('sends a null price and null url when left blank', async () => {
    const onAddManual = jest.fn().mockResolvedValue(true);
    const tree = render(
      <AccommodationBlock accommodations={[]} onSelect={jest.fn()} onAddManual={onAddManual} />,
    );
    openForm(tree);
    setField(tree, P.accommodationManualNamePlaceholder, 'Chez untel');
    setField(tree, P.accommodationManualAddressPlaceholder, '1 rue de la Paix, Lille');

    await act(async () => {
      await btn(tree, P.accommodationManualSave).props.onPress();
    });

    expect(onAddManual).toHaveBeenCalledWith({
      name: 'Chez untel',
      address: '1 rue de la Paix, Lille',
      priceTotal: null,
      url: null,
    });
  });

  it('keeps the form open when the backend rejects the entry (e.g. geocoding 422)', async () => {
    const onAddManual = jest.fn().mockResolvedValue(false);
    const tree = render(
      <AccommodationBlock accommodations={[]} onSelect={jest.fn()} onAddManual={onAddManual} />,
    );
    openForm(tree);
    setField(tree, P.accommodationManualNamePlaceholder, 'Gîte Test');
    setField(tree, P.accommodationManualAddressPlaceholder, 'Adresse introuvable');

    await act(async () => {
      await btn(tree, P.accommodationManualSave).props.onPress();
    });

    expect(onAddManual).toHaveBeenCalledTimes(1);
    // Form stays open so the user can correct the address.
    expect(
      tree.root.findAllByProps({ placeholder: P.accommodationManualNamePlaceholder }).length,
    ).toBeGreaterThan(0);
  });

  it('disables the save button until both name and address are filled', () => {
    const tree = render(
      <AccommodationBlock accommodations={[]} onSelect={jest.fn()} onAddManual={jest.fn()} />,
    );
    openForm(tree);
    const save = () => btn(tree, P.accommodationManualSave);

    expect(save().props.disabled).toBe(true);
    setField(tree, P.accommodationManualNamePlaceholder, 'Gîte Test');
    expect(save().props.disabled).toBe(true); // address still empty
    setField(tree, P.accommodationManualAddressPlaceholder, 'Grand Place, Lille');
    expect(save().props.disabled).toBe(false);
  });

  it('cancel closes the form without submitting', () => {
    const onAddManual = jest.fn();
    const tree = render(
      <AccommodationBlock accommodations={[]} onSelect={jest.fn()} onAddManual={onAddManual} />,
    );
    openForm(tree);
    act(() => btn(tree, P.accommodationManualCancel).props.onPress());

    expect(onAddManual).not.toHaveBeenCalled();
    expect(
      tree.root.findAllByProps({ placeholder: P.accommodationManualNamePlaceholder }).length,
    ).toBe(0);
    // Back to the add-manual affordance.
    expect(hasBtn(tree, P.accommodationAddManual)).toBe(true);
  });

  it('renders a selected manual accommodation with its source badge and no add form', () => {
    const t = texts(
      render(
        <AccommodationBlock
          accommodations={[]}
          selectedAccommodation={acc({
            name: "Chez l'habitant",
            type: 'other',
            source: 'manual',
            isExactPrice: true,
            estimatedPriceMin: 45,
            estimatedPriceMax: 45,
            address: '2 Grand Place, Lille',
          })}
          onSelect={jest.fn()}
          onAddManual={jest.fn()}
        />,
      ),
    );
    expect(t).toContain("Chez l'habitant");
    expect(t).toContain(P.accommodationSelected);
    expect(t.join(' ')).toContain(P.accommodationSourceManual);
  });
});

function stageFixture(overrides: Partial<StageData> = {}): StageData {
  const p = { lat: 0, lon: 0, ele: 0 };
  return {
    dayNumber: 1,
    distance: 50,
    elevation: 0,
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
    accommodationSearchRadiusKm: 5,
    isRestDay: false,
    supplyTimeline: [],
    events: [],
    ...overrides,
  } as StageData;
}

// Wires the manual form all the way through the real mutation runner (not just
// AccommodationBlock's `onAddManual` prop), so the actionable 422 message
// (StageDataBlocks' Alert.alert wiring, mirroring the web's visible error text
// in manual-accommodation.spec.ts) is actually exercised, not merely implied by
// "the form stayed open".
describe('StageDataBlocks — manual accommodation 422 surfacing (#1097)', () => {
  beforeEach(() => {
    useTripStore.setState({ tripId: 't1', isLocked: false, outOfZone: false });
    useOfflineStore.setState({ isOnline: true, apiReachable: true });
  });

  afterEach(() => {
    // Runs before the top-level unmount afterEach (inner describes fire first),
    // so the store reset must itself be wrapped — the tree is still mounted.
    act(() => {
      useTripStore.getState().reset();
      useOfflineStore.setState({ isOnline: true, apiReachable: true });
    });
    jest.clearAllMocks();
  });

  it('surfaces the actionable geocode-failure alert on a 422, without crashing', async () => {
    mockAddManualAccommodation.mockResolvedValue({ ok: false, status: 422 });
    const alertSpy = jest.spyOn(Alert, 'alert').mockImplementation(() => {});

    const tree = render(<StageDataBlocks stage={stageFixture()} stageIndex={0} />);
    openForm(tree);
    setField(tree, P.accommodationManualNamePlaceholder, 'Gîte Test');
    setField(tree, P.accommodationManualAddressPlaceholder, 'Adresse introuvable');

    await act(async () => {
      await btn(tree, P.accommodationManualSave).props.onPress();
    });

    expect(mockAddManualAccommodation).toHaveBeenCalledWith('t1', 0, {
      name: 'Gîte Test',
      address: 'Adresse introuvable',
      priceTotal: null,
      url: null,
    });
    expect(alertSpy).toHaveBeenCalledWith(
      fr.trip.accommodationGeocodeFailedTitle,
      fr.trip.accommodationGeocodeFailedMessage,
    );
    // The form is still there (no crash, correctable in place).
    expect(
      tree.root.findAllByProps({ placeholder: P.accommodationManualNamePlaceholder }).length,
    ).toBeGreaterThan(0);

    alertSpy.mockRestore();
  });
});
