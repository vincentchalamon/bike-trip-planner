/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import type { ReactElement } from 'react';
import { TextInput } from 'react-native';
import { DeleteAccountForm } from './DeleteAccountForm';

type Tree = ReturnType<typeof TestRenderer.create>;

function render(element: ReactElement): Tree {
  let out!: Tree;
  act(() => {
    out = TestRenderer.create(element);
  });
  return out;
}

function confirmDisabled(tree: Tree): boolean {
  return Boolean(
    tree.root.findByProps({ accessibilityRole: 'button' }).props.accessibilityState.disabled,
  );
}

function type(tree: Tree, text: string): void {
  act(() => {
    tree.root.findByType(TextInput).props.onChangeText(text);
  });
}

describe('DeleteAccountForm', () => {
  it('keeps the confirm button disabled until the keyword is typed exactly', () => {
    const onConfirm = jest.fn();
    const tree = render(
      <DeleteAccountForm keyword="SUPPRIMER" confirmLabel="Supprimer" onConfirm={onConfirm} />,
    );

    // Empty input: disabled.
    expect(confirmDisabled(tree)).toBe(true);

    // Partial / near match: still disabled.
    type(tree, 'SUPPRI');
    expect(confirmDisabled(tree)).toBe(true);
    type(tree, 'supprimer');
    expect(confirmDisabled(tree)).toBe(true);
    type(tree, 'SUPPRIMER ');
    expect(confirmDisabled(tree)).toBe(true);

    // Exact match: enabled.
    type(tree, 'SUPPRIMER');
    expect(confirmDisabled(tree)).toBe(false);
  });

  it('fires onConfirm only once armed', () => {
    const onConfirm = jest.fn();
    const tree = render(
      <DeleteAccountForm keyword="SUPPRIMER" confirmLabel="Supprimer" onConfirm={onConfirm} />,
    );

    type(tree, 'SUPPRIMER');
    act(() => {
      tree.root.findByProps({ accessibilityRole: 'button' }).props.onPress();
    });
    expect(onConfirm).toHaveBeenCalledTimes(1);
  });
});
