import { reverseGeocode } from "@/lib/geocode/client";
import { useTripStore } from "@/store/trip-store";

/**
 * Fill in the start and end place names of the given stages, by reverse geocoding.
 *
 * The backend sends coordinates; the names are resolved client-side, which is why this is a
 * side effect and not part of the shared reducer.
 *
 * Each write targets the stage by its identifier, not by its position: a reply lands seconds
 * later, after a day may have been inserted or removed, and the position it was asked for then
 * belongs to another day. A stage that no longer exists simply gets no label.
 *
 * ⚠ Every write checks `signal.aborted` again AFTER its request resolves. Aborting the signal
 * does not unwind a reply already in flight, and without the second check a late Nominatim
 * answer from a trip the user has navigated away from overwrites the labels of the one they are
 * looking at (#787).
 */
export async function resolveStageLabels(
  stages: {
    id: string;
    startPoint: { lat: number; lon: number };
    endPoint: { lat: number; lon: number };
  }[],
  signal?: AbortSignal,
): Promise<void> {
  const store = useTripStore.getState();

  await Promise.all(
    stages.flatMap((stage) => {
      const write =
        (field: "startLabel" | "endLabel") =>
        (result: { name: string } | null) => {
          if (result && !signal?.aborted) {
            store.updateStageLabel(stage.id, field, result.name);
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
