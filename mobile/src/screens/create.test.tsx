/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import { createElement } from 'react';
import i18n from '../i18n';

// create.tsx pulls in the trip barrel (SseStatusIndicator), which transitively
// imports the native maplibre module — stub it so jest can load the screen.
jest.mock('@maplibre/maplibre-react-native', () => ({
  Camera: () => null,
  GeoJSONSource: ({ children }: { children?: unknown }) => children ?? null,
  Layer: () => null,
  Map: ({ children }: { children?: unknown }) => children ?? null,
}));

jest.mock('expo-router', () => ({
  useLocalSearchParams: () => ({}),
  useRouter: () => ({ push: jest.fn() }),
}));

jest.mock('../hooks/use-analysis-follow', () => {
  const actual = jest.requireActual('../hooks/use-analysis-follow');
  return { ...actual, useAnalysisFollow: () => actual.INITIAL_FOLLOW_STATE };
});

jest.mock('../store/create-trip', () => {
  const actual = jest.requireActual('../store/create-trip');
  return {
    ...actual,
    runCreateTrip: jest.fn(),
    runUploadGpx: jest.fn(),
    pickGpxFile: jest.fn(),
  };
});
import { pickGpxFile, runCreateTrip, runUploadGpx } from '../store/create-trip';
import Create from '../../app/(tabs)/create';

const mockRunCreate = runCreateTrip as jest.MockedFunction<typeof runCreateTrip>;
const mockRunUpload = runUploadGpx as jest.MockedFunction<typeof runUploadGpx>;
const mockPick = pickGpxFile as jest.MockedFunction<typeof pickGpxFile>;

const VALID_LINK = 'https://www.strava.com/routes/1';

function deferred<T>(): { promise: Promise<T>; resolve: (v: T) => void } {
  let resolve!: (v: T) => void;
  const promise = new Promise<T>((r) => {
    resolve = r;
  });
  return { promise, resolve };
}

// Walk up from the label Text host to the Button's Pressable (carries `disabled`).
function findButtonByLabel(root: any, label: string): any {
  const texts = root.findAll(
    (n: any) => typeof n.type === 'string' && n.props?.children === label,
  );
  for (const text of texts) {
    let p: any = text.parent;
    while (p) {
      // The composite Pressable (not its host View) carries both onPress and the
      // computed accessibilityState — walk past the host to reach it.
      if (p.props?.accessibilityRole === 'button' && typeof p.props?.onPress === 'function') {
        return p;
      }
      p = p.parent;
    }
  }
  throw new Error(`button not found: ${label}`);
}

function findLinkInput(root: any): any {
  return root.findAll((n: any) => typeof n.type === 'string' && n.type === 'TextInput')[0];
}

let renderer: any;
function render(): any {
  act(() => {
    renderer = TestRenderer.create(createElement(Create));
  });
  return renderer.root;
}

const submitLabel = () => i18n.t('create.submit');
const gpxLabel = () => i18n.t('create.gpxImport');

beforeAll(async () => {
  await i18n.changeLanguage('fr');
});

beforeEach(() => jest.clearAllMocks());

afterEach(() => {
  act(() => renderer?.unmount());
});

describe('Create screen — mutually exclusive creation flows (#1043)', () => {
  it('disables the link button while a GPX upload is in flight', async () => {
    mockPick.mockResolvedValue({ uri: 'file:///r.gpx', name: 'r.gpx' });
    const upload = deferred<string | null>();
    mockRunUpload.mockReturnValue(upload.promise);

    const root = render();

    // A valid link makes the link button enabled on its own merits...
    await act(async () => {
      findLinkInput(root).props.onChangeText(VALID_LINK);
    });
    expect(findButtonByLabel(root, submitLabel()).props.accessibilityState.disabled).toBe(false);

    // ...but starting a GPX upload must disable it (single-flight creation).
    await act(async () => {
      findButtonByLabel(root, gpxLabel()).props.onPress();
    });
    expect(mockRunUpload).toHaveBeenCalledTimes(1);
    expect(findButtonByLabel(root, submitLabel()).props.accessibilityState.disabled).toBe(true);

    await act(async () => {
      upload.resolve(null);
    });
  });

  it('disables the GPX button while a link creation is in flight', async () => {
    const create = deferred<string | null>();
    mockRunCreate.mockReturnValue(create.promise);

    const root = render();

    // GPX button is enabled on its own...
    expect(findButtonByLabel(root, gpxLabel()).props.accessibilityState.disabled).toBe(false);

    // ...until a link creation is submitted.
    await act(async () => {
      findLinkInput(root).props.onChangeText(VALID_LINK);
    });
    await act(async () => {
      findButtonByLabel(root, submitLabel()).props.onPress();
    });
    expect(mockRunCreate).toHaveBeenCalledTimes(1);
    expect(findButtonByLabel(root, gpxLabel()).props.accessibilityState.disabled).toBe(true);

    await act(async () => {
      create.resolve(null);
    });
  });
});
