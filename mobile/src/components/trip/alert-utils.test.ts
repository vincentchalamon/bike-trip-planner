/// <reference types="jest" />
import type { AlertData } from '@btp/core';
import {
  alertDedupKey,
  dedupeAlerts,
  groupBySeverity,
  visibleAlerts,
} from './alert-utils';

function alert(overrides: Partial<AlertData> = {}): AlertData {
  return {
    type: 'warning',
    code: 'resupply_closed_at_passage',
    message: 'Des commerces existent, mais hors des horaires.',
    ...overrides,
  } as AlertData;
}

describe('alertDedupKey', () => {
  it('is stable when the message is reworded (keys on code)', () => {
    expect(alertDedupKey(alert())).toBe(
      alertDedupKey(alert({ message: "Hors des heures d'ouverture." })),
    );
  });

  it('keeps two variants of the same family apart (distinct codes)', () => {
    expect(alertDedupKey(alert({ code: 'ford_crossing_dry' }))).not.toBe(
      alertDedupKey(alert({ code: 'ford_crossing_wet' })),
    );
  });

  it('falls back to the message when the code is absent (legacy alert)', () => {
    expect(alertDedupKey(alert({ code: undefined }))).toBe(alert().message);
  });
});

describe('dedupeAlerts', () => {
  it('collapses two alerts of the same code to the first, preserving order', () => {
    const out = dedupeAlerts([
      alert({ message: 'A' }),
      alert({ message: 'B' }),
    ]);
    expect(out).toHaveLength(1);
    expect(out[0]!.message).toBe('A');
  });

  it('keeps distinct codes', () => {
    const out = dedupeAlerts([
      alert({ code: 'a' }),
      alert({ code: 'b' }),
    ]);
    expect(out).toHaveLength(2);
  });
});

describe('visibleAlerts', () => {
  it('drops an alert whose code is dismissed', () => {
    const out = visibleAlerts(
      [alert({ code: 'a' }), alert({ code: 'b' })],
      new Set(['a']),
    );
    expect(out.map((a) => a.code)).toEqual(['b']);
  });

  it('dedups before filtering (a dismissed code hides all its occurrences)', () => {
    const out = visibleAlerts(
      [alert({ code: 'a', message: 'x' }), alert({ code: 'a', message: 'y' })],
      new Set(['a']),
    );
    expect(out).toHaveLength(0);
  });
});

describe('groupBySeverity', () => {
  it('buckets by type', () => {
    const groups = groupBySeverity([
      alert({ type: 'nudge', code: 'n' }),
      alert({ type: 'critical', code: 'c' }),
      alert({ type: 'warning', code: 'w' }),
    ]);
    expect(groups.critical).toHaveLength(1);
    expect(groups.warning).toHaveLength(1);
    expect(groups.nudge).toHaveLength(1);
  });
});
