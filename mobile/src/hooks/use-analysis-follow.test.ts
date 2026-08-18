/// <reference types="jest" />
import type { MercureEvent } from '@btp/core/mercure';
import {
  INITIAL_FOLLOW_STATE,
  reduceAnalysisEvent,
  type AnalysisFollowState,
} from './use-analysis-follow';

function step(completed: number, total: number): MercureEvent {
  return {
    type: 'computation_step_completed',
    data: { step: 's', category: 'route', completed, total },
  };
}

describe('reduceAnalysisEvent', () => {
  it('tracks progress on a computation step and stays computing', () => {
    const next = reduceAnalysisEvent(INITIAL_FOLLOW_STATE, step(2, 7));
    expect(next).toMatchObject({ computing: true, done: false, completed: 2, total: 7 });
  });

  it('flips done and stops computing on trip_ready', () => {
    const running: AnalysisFollowState = { ...INITIAL_FOLLOW_STATE, completed: 3, total: 7 };
    const next = reduceAnalysisEvent(running, {
      type: 'trip_ready',
      data: { stages: [], computationStatus: {} },
    });
    expect(next).toMatchObject({ computing: false, done: true });
  });

  it('flips done and stops computing on trip_complete', () => {
    const next = reduceAnalysisEvent(INITIAL_FOLLOW_STATE, {
      type: 'trip_complete',
      data: { computationStatus: {} },
    });
    expect(next).toMatchObject({ computing: false, done: true });
  });

  it('leaves state untouched on a retryable computation_error', () => {
    const running = reduceAnalysisEvent(INITIAL_FOLLOW_STATE, step(1, 4));
    const next = reduceAnalysisEvent(running, {
      type: 'computation_error',
      data: { computation: 'weather', message: 'x', retryable: true },
    });
    expect(next).toBe(running);
    expect(next.failed).toBe(false);
  });

  it('marks failed and stops computing on a non-retryable error', () => {
    const next = reduceAnalysisEvent(INITIAL_FOLLOW_STATE, {
      type: 'computation_error',
      data: { computation: 'route', message: 'x', retryable: false },
    });
    expect(next).toMatchObject({ computing: false, failed: true });
  });

  it('ignores unrelated events', () => {
    const next = reduceAnalysisEvent(INITIAL_FOLLOW_STATE, {
      type: 'stage_updated',
      data: { stageIndex: 0, stage: {} as never },
    });
    expect(next).toBe(INITIAL_FOLLOW_STATE);
  });
});
