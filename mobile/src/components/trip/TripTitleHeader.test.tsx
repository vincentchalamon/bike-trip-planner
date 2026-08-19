/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import type { ReactElement } from 'react';
import { TextInput } from 'react-native';
import i18n from '../../i18n';
import { fr } from '../../i18n/resources/fr';
import { useTripStore } from '../../store/trip-store';

const mockUpdateTitle = jest.fn();
jest.mock('../../hooks/use-trip-mutations', () => ({
  useTripMutations: () => ({ updateTitle: mockUpdateTitle }),
}));

import { TripTitleHeader } from './TripTitleHeader';

const rendered: any[] = [];
function render(element: ReactElement): any {
  let out: any;
  act(() => {
    out = TestRenderer.create(element);
  });
  rendered.push(out);
  return out;
}

function press(tree: any, label: string): void {
  act(() => tree.root.findByProps({ accessibilityLabel: label }).props.onPress());
}

beforeAll(async () => {
  await i18n.changeLanguage('fr');
});

beforeEach(() => {
  jest.clearAllMocks();
  useTripStore.getState().reset();
});

afterEach(() => {
  act(() => {
    while (rendered.length) rendered.pop()!.unmount();
  });
});

describe('TripTitleHeader (#1105)', () => {
  it('renames the trip from the header edit affordance', () => {
    useTripStore.setState({ title: 'Ancien', isLocked: false });
    const tree = render(<TripTitleHeader tripId="t1" />);

    // Opens the editor, types a new title, saves.
    press(tree, fr.trip.editTitleA11y);
    act(() =>
      tree.root.findByType(TextInput).props.onChangeText('Nouveau titre'),
    );
    press(tree, fr.trip.saveTitleA11y);

    expect(mockUpdateTitle).toHaveBeenCalledWith('Nouveau titre');
  });

  it('disables the edit affordance and shows no editor when the trip is locked', () => {
    useTripStore.setState({ title: 'Verrouillé', isLocked: true });
    const tree = render(<TripTitleHeader tripId="t1" />);

    expect(
      tree.root.findByProps({ accessibilityLabel: fr.trip.editTitleA11y }).props
        .disabled,
    ).toBe(true);
    expect(tree.root.findAllByType(TextInput)).toHaveLength(0);
  });
});
