import { reverseGeocode } from "@/lib/geocode/client";
import { useTripStore } from "@/store/trip-store";

/**
 * Fill in the start and end place names of the given stages, by reverse geocoding.
 *
 * The backend sends coordinates; the names are resolved client-side, which is why this is a
 * side effect and not part of the shared reducer.
 *
 * `indices` says where each stage sits in the store, because callers routinely pass a filtered
 * subset — the stages missing a label, or the ones an event named — and writing back by array
 * position would then label the wrong days.
 *
 * ⚠ Every write checks `signal.aborted` again AFTER its request resolves. Aborting the signal
 * does not unwind a reply already in flight, and without the second check a late Nominatim
 * answer from a trip the user has navigated away from overwrites the labels of the one they are
 * looking at (#787).
 */
export async function resolveStageLabels(
  stages: {
    startPoint: { lat: number; lon: number };
    endPoint: { lat: number; lon: number };
  }[],
  indices?: number[],
  signal?: AbortSignal,
): Promise<void> {
  const store = useTripStore.getState();

  await Promise.all(
    stages.flatMap((stage, i) => {
      const storeIndex = indices ? (indices[i] ?? i) : i;

      const write =
        (field: "startLabel" | "endLabel") =>
        (result: { name: string } | null) => {
          if (result && !signal?.aborted) {
            store.updateStageLabel(storeIndex, field, result.name);
          }
        };

      return [
        reverseGeocode(stage.startPoint.lat, stage.startPoint.lon, signal).then(
          write("startLabel"),
        ),
        reverseGeocode(stage.endPoint.lat, stage.endPoint.lon, signal).then(
          write("endLabel"),
        ),
      ];
    }),
  );
}
