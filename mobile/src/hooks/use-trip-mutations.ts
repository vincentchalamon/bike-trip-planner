import { useMemo } from 'react';
import { useTripStore } from '../store/trip-store';
import type { MutationContext, OnFailure } from '../store/mutations';
import { runDeleteStage } from '../store/delete-stage';
import type { TripConfig } from '../store/trip-store';
import {
  runAddPoiWaypoint,
  runAddStage,
  runAnalyze,
  runApplyBatch,
  runDeleteTrip,
  runDeselectAccommodation,
  runDuplicateTrip,
  runInsertRestDay,
  runMoveStage,
  runScanAccommodations,
  runSelectAccommodation,
  runUpdateAccommodationTypes,
  runUpdateDates,
  runUpdatePacing,
  runUpdateStageDistance,
  runUpdateTitle,
} from '../store/mutations';

type Pacing = Pick<
  TripConfig,
  | 'fatigueFactor'
  | 'elevationPenalty'
  | 'maxDistancePerDay'
  | 'averageSpeed'
  | 'ebikeMode'
  | 'departureHour'
>;

// Bind every mutation runner to the live store snapshot + a single failure
// callback, so an editing screen (Sprint 55/56) calls `mutations.updateDates(...)`
// without re-assembling the context or the gate each time. The store snapshot is
// read at call time (actions are stable, the pre-edit `stages` snapshot is taken
// inside each runner) so a stale closure never rolls back the wrong state.
export function useTripMutations(tripId: string, onFailure: OnFailure) {
  return useMemo(() => {
    const ctx = (): MutationContext => useTripStore.getState();
    return {
      updateDates: (startDate: string | null, endDate: string | null) =>
        runUpdateDates(tripId, startDate, endDate, ctx(), onFailure),
      updatePacing: (pacing: Pacing) =>
        runUpdatePacing(tripId, pacing, ctx(), onFailure),
      updateTitle: (title: string) =>
        runUpdateTitle(tripId, title, ctx(), onFailure),
      updateAccommodationTypes: (types: string[]) =>
        runUpdateAccommodationTypes(tripId, types, ctx(), onFailure),
      addStage: (afterIndex: number) =>
        runAddStage(tripId, afterIndex, ctx(), onFailure),
      deleteStage: (index: number) =>
        runDeleteStage(tripId, index, ctx(), onFailure),
      insertRestDay: (afterIndex: number) =>
        runInsertRestDay(tripId, afterIndex, ctx(), onFailure),
      moveStage: (fromIndex: number, toIndex: number) =>
        runMoveStage(tripId, fromIndex, toIndex, ctx(), onFailure),
      updateStageDistance: (index: number, distance: number) =>
        runUpdateStageDistance(tripId, index, distance, ctx(), onFailure),
      selectAccommodation: (stageIndex: number, accIndex: number) =>
        runSelectAccommodation(tripId, stageIndex, accIndex, ctx(), onFailure),
      deselectAccommodation: (stageIndex: number) =>
        runDeselectAccommodation(tripId, stageIndex, ctx(), onFailure),
      scanAccommodations: (radiusKm: number, stageIndex?: number) =>
        runScanAccommodations(tripId, radiusKm, stageIndex, ctx(), onFailure),
      addPoiWaypoint: (stageIndex: number, lat: number, lon: number) =>
        runAddPoiWaypoint(tripId, stageIndex, lat, lon, ctx(), onFailure),
      applyBatch: () => runApplyBatch(tripId, ctx(), onFailure),
      analyze: () => runAnalyze(tripId, ctx(), onFailure),
      duplicate: () => runDuplicateTrip(tripId, ctx(), onFailure),
      deleteTrip: () => runDeleteTrip(tripId, ctx(), onFailure),
    };
  }, [tripId, onFailure]);
}
