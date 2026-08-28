/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import type { ReactElement } from 'react';
import { Text } from 'react-native';
import { Button } from './Button';
import { EmptyState } from './EmptyState';
import { Input } from './Input';
import { LoadingState } from './LoadingState';
import { SegmentedControl } from './SegmentedControl';
import { ThemeContext } from '../../theme/context';
import { darkColors } from '../../theme/tokens';
import { darkTheme } from '../../theme/theme';

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

describe('ui primitives (no provider → light fallback)', () => {
  it('Button renders its label and fires onPress', () => {
    const onPress = jest.fn();
    const tree = render(<Button label="Valider" onPress={onPress} />);
    expect(texts(tree)).toContain('Valider');
    act(() => {
      tree.root.findByProps({ accessibilityRole: 'button' }).props.onPress();
    });
    expect(onPress).toHaveBeenCalledTimes(1);
  });

  it('Button hides its label while loading', () => {
    const tree = render(<Button label="Valider" loading />);
    expect(texts(tree)).not.toContain('Valider');
  });

  it('Button pads the tap area on the sm size, under the 44pt minimum (#1233)', () => {
    const tree = render(<Button label="Annuler" size="sm" onPress={jest.fn()} />);
    const pressable = tree.root.findByProps({ accessibilityRole: 'button' });
    expect(pressable.props.hitSlop).toBeTruthy();
  });

  it('Button does not pad the tap area on md/lg, already >=44pt (#1233)', () => {
    const tree = render(<Button label="Annuler" size="md" onPress={jest.fn()} />);
    const pressable = tree.root.findByProps({ accessibilityRole: 'button' });
    expect(pressable.props.hitSlop).toBeFalsy();
  });

  it('Button outlineForest uses the dark-mode-accessible forestText in dark scheme (#1233)', () => {
    const tree = render(
      <ThemeContext.Provider value={darkTheme}>
        <Button label="Importer un GPX" variant="outlineForest" onPress={jest.fn()} />
      </ThemeContext.Provider>,
    );
    const label = tree.root.findByProps({ children: 'Importer un GPX' });
    expect(label.props.style.color).toBe(darkColors.forestText);
    // `forest` alone is a 2.36:1 fail against the dark background — guard
    // against silently falling back to it.
    expect(label.props.style.color).not.toBe(darkColors.forest);
  });

  it('SegmentedControl pads each segment vertically, under the 44pt minimum (#1233)', () => {
    const tree = render(
      <SegmentedControl
        segments={[
          { value: 'a', label: 'A' },
          { value: 'b', label: 'B' },
        ]}
        value="a"
        onChange={jest.fn()}
      />,
    );
    // findAllByProps also matches the host node(s) RN's Pressable renders
    // internally; only the outer composite instance keeps a plain `onPress`
    // (the host nodes get it translated into onResponderGrant/etc).
    const outer = tree.root
      .findAllByProps({ accessibilityRole: 'button' })
      .filter((n: any) => typeof n.props.onPress === 'function');
    expect(outer).toHaveLength(2);
    for (const node of outer) {
      expect(node.props.hitSlop).toEqual(expect.objectContaining({ top: expect.any(Number) }));
    }
  });

  it('EmptyState renders title and description', () => {
    const tree = render(
      <EmptyState title="Aucun voyage" description="Créez votre premier itinéraire" />,
    );
    const t = texts(tree);
    expect(t).toContain('Aucun voyage');
    expect(t).toContain('Créez votre premier itinéraire');
  });

  it('Input renders its label and error', () => {
    const tree = render(<Input label="Email" error="Requis" />);
    const t = texts(tree);
    expect(t).toContain('Email');
    expect(t).toContain('Requis');
  });

  it('LoadingState renders without crashing', () => {
    expect(() => render(<LoadingState label="Chargement" />)).not.toThrow();
  });
});
