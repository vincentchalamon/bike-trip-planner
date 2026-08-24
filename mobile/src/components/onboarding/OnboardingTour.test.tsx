/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import { createElement } from 'react';
import i18n from '../../i18n';
import { useOnboarding } from '../../store/onboarding-prefs';
import { OnboardingTour } from './OnboardingTour';

jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn().mockResolvedValue(null),
  setItemAsync: jest.fn(),
}));
import * as SecureStore from 'expo-secure-store';
const setItem = SecureStore.setItemAsync as jest.Mock;

let renderer: any;
async function render(): Promise<any> {
  await act(async () => {
    renderer = TestRenderer.create(createElement(OnboardingTour));
  });
  return renderer.root;
}

// The tour's root View carries testID="onboarding-tour"; absent => renders null.
function isVisible(root: any): boolean {
  return root.findAllByProps({ testID: 'onboarding-tour' }).length > 0;
}
function findSkip(root: any): any {
  return root.find(
    (n: any) =>
      n.props?.accessibilityRole === 'button' &&
      n.props?.accessibilityLabel === i18n.t('onboarding.skip'),
  );
}
// The bottom CTA is a Button rendered with a `label` prop (Suivant / Commencer).
function findCta(root: any): any {
  return root.find((n: any) => typeof n.props?.label === 'string' && !!n.props?.onPress);
}

beforeAll(async () => {
  await i18n.changeLanguage('fr');
});

beforeEach(() => {
  setItem.mockReset();
  useOnboarding.setState({ seen: false, hydrated: false });
});

afterEach(() => {
  act(() => renderer?.unmount());
});

describe('OnboardingTour', () => {
  it('renders on first run once the unset flag has hydrated', async () => {
    const root = await render();
    // load() resolved with null -> hydrated, not seen -> the tour shows.
    expect(useOnboarding.getState()).toMatchObject({ seen: false, hydrated: true });
    expect(isVisible(root)).toBe(true);
  });

  it('does not render once the flag is already set', async () => {
    useOnboarding.setState({ seen: true, hydrated: true });
    const root = await render();
    expect(isVisible(root)).toBe(false);
  });

  it('advances through the steps via Next and finishes (markSeen) on the last (#1202 review)', async () => {
    const root = await render();
    // Step 1/3, CTA reads "Suivant".
    expect(findCta(root).props.label).toBe(i18n.t('onboarding.next'));

    await act(async () => findCta(root).props.onPress()); // -> step 2
    expect(findCta(root).props.label).toBe(i18n.t('onboarding.next'));
    await act(async () => findCta(root).props.onPress()); // -> step 3 (last)

    // On the last step the CTA becomes "Commencer" and does not mark seen yet.
    expect(findCta(root).props.label).toBe(i18n.t('onboarding.start'));
    expect(useOnboarding.getState().seen).toBe(false);

    await act(async () => findCta(root).props.onPress()); // finish
    expect(useOnboarding.getState().seen).toBe(true);
    expect(setItem).toHaveBeenCalledWith('btp_onboarding_seen', 'true');
    expect(isVisible(root)).toBe(false);
  });

  it('Skip marks the tour as shown and dismisses it', async () => {
    const root = await render();
    expect(isVisible(root)).toBe(true);

    await act(async () => {
      findSkip(root).props.onPress();
    });

    expect(useOnboarding.getState().seen).toBe(true);
    expect(setItem).toHaveBeenCalledWith('btp_onboarding_seen', 'true');
    expect(isVisible(root)).toBe(false);
  });
});
