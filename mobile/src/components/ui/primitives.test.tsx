/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import type { ReactElement } from 'react';
import { Text } from 'react-native';
import { Button } from './Button';
import { EmptyState } from './EmptyState';
import { Input } from './Input';
import { LoadingState } from './LoadingState';

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
