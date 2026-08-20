/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import type { ReactElement } from 'react';
import { Text, TextInput } from 'react-native';
import { DeleteAccountForm } from './DeleteAccountForm';

type Tree = ReturnType<typeof TestRenderer.create>;
type Instance = ReturnType<Tree['root']['find']>;

const CONFIRM = 'Supprimer';
const CANCEL = 'Annuler';

function render(element: ReactElement): Tree {
  let out!: Tree;
  act(() => {
    out = TestRenderer.create(element);
  });
  return out;
}

// Locate a Button (Pressable) by the label text it renders, so the two buttons
// (Cancel / Delete) are told apart without relying on order.
function buttonByLabel(tree: Tree, label: string): Instance {
  const btn = tree.root
    .findAll((n: Instance) => n.props.accessibilityRole === 'button')
    .find((b: Instance) =>
      b.findAllByType(Text).some((t: Instance) => {
        const kids = Array.isArray(t.props.children) ? t.props.children : [t.props.children];
        return kids.includes(label);
      }),
    );
  if (!btn) throw new Error(`button "${label}" not found`);
  return btn;
}

function confirmDisabled(tree: Tree): boolean {
  return Boolean(buttonByLabel(tree, CONFIRM).props.accessibilityState.disabled);
}

function type(tree: Tree, text: string): void {
  act(() => {
    tree.root.findByType(TextInput).props.onChangeText(text);
  });
}

function form(overrides: Partial<Parameters<typeof DeleteAccountForm>[0]> = {}): ReactElement {
  return (
    <DeleteAccountForm
      keyword="SUPPRIMER"
      confirmLabel={CONFIRM}
      cancelLabel={CANCEL}
      onConfirm={jest.fn()}
      onCancel={jest.fn()}
      {...overrides}
    />
  );
}

describe('DeleteAccountForm', () => {
  it('keeps the confirm button disabled until the keyword is typed exactly', () => {
    const tree = render(form());

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
    const tree = render(form({ onConfirm }));

    type(tree, 'SUPPRIMER');
    act(() => {
      buttonByLabel(tree, CONFIRM).props.onPress();
    });
    expect(onConfirm).toHaveBeenCalledTimes(1);
  });

  it('fires onCancel from the cancel button without any input', () => {
    const onCancel = jest.fn();
    const tree = render(form({ onCancel }));

    act(() => {
      buttonByLabel(tree, CANCEL).props.onPress();
    });
    expect(onCancel).toHaveBeenCalledTimes(1);
  });
});
