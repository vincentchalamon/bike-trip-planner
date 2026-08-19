/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import type { ReactElement } from 'react';
import { Text } from 'react-native';

// Capture the native picker's props so a test can drive its onChange without a
// real calendar dialog. `mock`-prefixed so the jest.mock factory may close over it.
type PickerProps = { onChange: (e: { type: string }, d?: Date) => void };
const mockPicker = jest.fn((_props: PickerProps) => null);
jest.mock('@react-native-community/datetimepicker', () => ({
  __esModule: true,
  default: (props: PickerProps) => mockPicker(props),
}));

import { DateField } from './DateField';

function render(el: ReactElement): any {
  let out: any;
  act(() => {
    out = TestRenderer.create(el);
  });
  return out;
}
function texts(tree: any): string[] {
  return tree.root
    .findAllByType(Text)
    .flatMap((n: any) => {
      const kids = Array.isArray(n.props.children) ? n.props.children : [n.props.children];
      return kids.filter((c: unknown): c is string => typeof c === 'string');
    });
}
function button(tree: any, label: string): any {
  return tree.root
    .findAllByProps({ accessibilityRole: 'button' })
    .find((b: any) => b.props.accessibilityLabel === label);
}

beforeEach(() => mockPicker.mockClear());

const base = {
  placeholder: 'Choisir',
  accessibilityLabel: 'Date de début',
  clearLabel: 'Effacer',
};

describe('DateField', () => {
  it('shows the placeholder when there is no value', () => {
    const tree = render(<DateField {...base} value="" onChange={jest.fn()} />);
    expect(texts(tree)).toContain('Choisir');
  });

  it('shows a localised date when a value is set', () => {
    const tree = render(<DateField {...base} value="2026-08-15" onChange={jest.fn()} />);
    // fr default locale in this suite -> "15 août 2026" (day/short-month/year).
    expect(texts(tree).join(' ')).toMatch(/15.*2026/);
  });

  it('commits the picked date as an ISO string on "set"', () => {
    const onChange = jest.fn();
    const tree = render(<DateField {...base} value="" onChange={onChange} />);
    act(() => button(tree, 'Date de début').props.onPress());
    const props = mockPicker.mock.calls.at(-1)![0];
    act(() => props.onChange({ type: 'set' }, new Date(2026, 7, 20)));
    expect(onChange).toHaveBeenCalledWith('2026-08-20');
  });

  it('does not commit when the picker is dismissed', () => {
    const onChange = jest.fn();
    const tree = render(<DateField {...base} value="" onChange={onChange} />);
    act(() => button(tree, 'Date de début').props.onPress());
    const props = mockPicker.mock.calls.at(-1)![0];
    act(() => props.onChange({ type: 'dismissed' }, undefined));
    expect(onChange).not.toHaveBeenCalled();
  });

  it('clears back to an empty string', () => {
    const onChange = jest.fn();
    const tree = render(<DateField {...base} value="2026-08-15" onChange={onChange} />);
    act(() => button(tree, 'Effacer').props.onPress());
    expect(onChange).toHaveBeenCalledWith('');
  });

  it('does not open the picker when disabled', () => {
    const tree = render(<DateField {...base} value="" onChange={jest.fn()} disabled />);
    const field = button(tree, 'Date de début');
    expect(field.props.disabled).toBe(true);
    expect(field.props.accessibilityState).toEqual({ disabled: true });
  });
});
