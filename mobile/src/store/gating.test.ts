/// <reference types="jest" />
import { evaluateGate, normalizeStatus, type GateState } from './gating';

const open: GateState = {
  isLocked: false,
  outOfZone: false,
  isOnline: true,
  apiReachable: true,
};

describe('evaluateGate (transversal mutation gate, #1031)', () => {
  it('allows a routing mutation when online, unlocked and in zone', () => {
    expect(evaluateGate(open, true)).toBeNull();
    expect(evaluateGate(open, false)).toBeNull();
  });

  it('blocks everything while offline (wins over lock, zone and api health)', () => {
    expect(
      evaluateGate(
        { isLocked: true, outOfZone: true, isOnline: false, apiReachable: false },
        true,
      ),
    ).toBe('offline');
    expect(evaluateGate({ ...open, isOnline: false }, false)).toBe('offline');
  });

  it('blocks everything when the API is unreachable while online (#1166)', () => {
    // Online but the API is down: read-only, wins over lock and zone.
    expect(
      evaluateGate({ ...open, apiReachable: false, isLocked: true }, true),
    ).toBe('api_unavailable');
    expect(evaluateGate({ ...open, apiReachable: false }, false)).toBe(
      'api_unavailable',
    );
    // Offline still wins over api_unavailable.
    expect(
      evaluateGate({ ...open, isOnline: false, apiReachable: false }, false),
    ).toBe('offline');
  });

  it('blocks every mutation on a started (423) trip', () => {
    expect(evaluateGate({ ...open, isLocked: true }, true)).toBe('locked');
    expect(evaluateGate({ ...open, isLocked: true }, false)).toBe('locked');
  });

  it('blocks only routing mutations out of zone', () => {
    expect(evaluateGate({ ...open, outOfZone: true }, true)).toBe('out_of_zone');
    // A non-routing mutation (title, dates, pacing, scan) is still allowed.
    expect(evaluateGate({ ...open, outOfZone: true }, false)).toBeNull();
  });
});

describe('normalizeStatus (API error contract, CLAUDE.md)', () => {
  it('maps 422 to a validation failure (unknown enum → 422, not 400)', () => {
    expect(normalizeStatus(422)).toBe('validation');
  });

  it('maps 404 to not_found (object-authz denials are masked as 404)', () => {
    expect(normalizeStatus(404)).toBe('not_found');
  });

  it('maps 423 to locked and 409 to conflict', () => {
    expect(normalizeStatus(423)).toBe('locked');
    expect(normalizeStatus(409)).toBe('conflict');
  });

  it('maps status 0 to network and any other status to a generic error', () => {
    expect(normalizeStatus(0)).toBe('network');
    expect(normalizeStatus(500)).toBe('error');
    expect(normalizeStatus(400)).toBe('error');
  });
});
