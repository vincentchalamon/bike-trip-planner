/// <reference types="jest" />
import TestRenderer, { act } from 'react-test-renderer';
import type { ReactElement } from 'react';
import i18n from '../../i18n';
import { fr } from '../../i18n/resources/fr';
import { StageInsertRow } from './StageInsertRow';

function render(element: ReactElement): any {
  let out: any;
  act(() => {
    out = TestRenderer.create(element);
  });
  return out;
}

function byLabel(tree: any, label: string): any {
  const found = tree.root.findAll(
    (n: any) => n.props.accessibilityLabel === label,
  );
  return found[0] ?? null;
}

const addStageA11y = (day: number) =>
  fr.trip.edit.addStageA11y.replace('{{day}}', String(day));
const addRestDayA11y = (day: number) =>
  fr.trip.edit.addRestDayA11y.replace('{{day}}', String(day));

describe('StageInsertRow', () => {
  beforeAll(async () => {
    await i18n.changeLanguage('fr');
  });

  it('calls onAddStage / onAddRestDay with the boundary index', () => {
    const onAddStage = jest.fn();
    const onAddRestDay = jest.fn();
    const tree = render(
      <StageInsertRow
        afterIndex={2}
        day={3}
        onAddStage={onAddStage}
        onAddRestDay={onAddRestDay}
      />,
    );
    act(() => byLabel(tree, addStageA11y(3)).props.onPress());
    act(() => byLabel(tree, addRestDayA11y(3)).props.onPress());
    expect(onAddStage).toHaveBeenCalledWith(2);
    expect(onAddRestDay).toHaveBeenCalledWith(2);
  });

  it('disables ＋étape out of zone but keeps ＋repos enabled', () => {
    const tree = render(
      <StageInsertRow
        afterIndex={0}
        day={1}
        outOfZone
        onAddStage={jest.fn()}
        onAddRestDay={jest.fn()}
      />,
    );
    expect(byLabel(tree, addStageA11y(1)).props.accessibilityState.disabled).toBe(true);
    expect(byLabel(tree, addRestDayA11y(1)).props.accessibilityState.disabled).toBe(false);
  });

  it('disables both pills while a mutation is in flight', () => {
    const tree = render(
      <StageInsertRow
        afterIndex={0}
        day={1}
        busy
        onAddStage={jest.fn()}
        onAddRestDay={jest.fn()}
      />,
    );
    expect(byLabel(tree, addStageA11y(1)).props.accessibilityState.disabled).toBe(true);
    expect(byLabel(tree, addRestDayA11y(1)).props.accessibilityState.disabled).toBe(true);
  });
});
