"use client";

import { useEffect, useState } from "react";
import { toast } from "@/components/ui/sonner";
import { useTranslations } from "next-intl";
import { useRouter } from "next/navigation";
import { useShallow } from "zustand/react/shallow";
import { useTripStore } from "@/store/trip-store";
import { useUiStore } from "@/store/ui-store";
import { useMercure } from "@/hooks/use-mercure";
import {
  apiClient,
  newIdempotencyKey,
  isNetworkError,
  uploadGpxFile,
  applyBatchRecompute,
} from "@/lib/api/client";
import { getRandomTripName } from "@/lib/trip-utils";
import { trackEvent, type PlausibleEvent } from "@/lib/plausible";
import {
  disarmRecomputeSafetyNet,
  getPacingState,
  useReportApiError,
} from "@/hooks/trip-mutation-support";

/** Map a source URL to its Plausible import event (null if unrecognised). */
export function importEventForUrl(url: string): PlausibleEvent | null {
  if (/komoot\.com\//.test(url)) return "import_komoot";
  if (/strava\.com\//.test(url)) return "import_strava";
  if (/ridewithgps\.com\//.test(url)) return "import_rwgps";
  return null;
}

/**
 * The trip page's own concerns: creating a trip, the live Mercure link, the batch queue and
 * the share modal. Stage, accommodation and trip-setting edits live in their own hooks
 * (`useStageMutations`, `useAccommodationMutations`, `useTripSettings`), read by the
 * components that trigger them.
 */
export function useTripPlanner() {
  const t = useTranslations();
  const router = useRouter();

  const reportApiError = useReportApiError();

  const {
    trip,
    totalDistance,
    totalElevation,
    totalElevationLoss,
    stages,
    startDate,
    endDate,
    isLocked,
    outOfZone,
  } = useTripStore(
    useShallow((s) => ({
      trip: s.trip,
      totalDistance: s.totalDistance,
      totalElevation: s.totalElevation,
      totalElevationLoss: s.totalElevationLoss,
      stages: s.stages,
      startDate: s.startDate,
      endDate: s.endDate,
      isLocked: s.isLocked,
      outOfZone: s.outOfZone,
    })),
  );

  const {
    fatigueFactor,
    elevationPenalty,
    maxDistancePerDay,
    averageSpeed,
    ebikeMode,
    departureHour,
    enabledAccommodationTypes,
  } = useTripStore(
    useShallow((s) => ({
      fatigueFactor: s.fatigueFactor,
      elevationPenalty: s.elevationPenalty,
      maxDistancePerDay: s.maxDistancePerDay,
      averageSpeed: s.averageSpeed,
      ebikeMode: s.ebikeMode,
      departureHour: s.departureHour,
      enabledAccommodationTypes: s.enabledAccommodationTypes,
    })),
  );

  const actions = useTripStore(
    useShallow((s) => ({
      setTrip: s.setTrip,
      clearTrip: s.clearTrip,
      setIsLocked: s.setIsLocked,
      startStageRecomputation: s.startStageRecomputation,
      cancelAllModifications: s.cancelAllModifications,
      clearPendingModifications: s.clearPendingModifications,
    })),
  );

  const pendingModifications = useTripStore((s) => s.pendingModifications);
  const [isBatchApplying, setIsBatchApplying] = useState(false);

  // UI store
  const isProcessing = useUiStore((s) => s.isProcessing);
  const setProcessing = useUiStore((s) => s.setProcessing);
  const setAccommodationScanning = useUiStore(
    (s) => s.setAccommodationScanning,
  );

  const tripId = trip?.id ?? null;
  useMercure(tripId);

  // Chat history is scoped to a single trip session. Wipe it whenever the
  // user switches trip so messages from trip A don't bleed into trip B's
  // panel after navigation.
  useEffect(() => {
    if (!tripId) return;
    useUiStore.getState().clearHistory();
  }, [tripId]);

  // Clear any pending recompute safety-net timer on unmount and whenever the
  // active trip changes, so a timer armed for one trip can never fire against
  // another or against a torn-down view (#840).
  useEffect(() => disarmRecomputeSafetyNet, [tripId]);

  async function handleMagicLink(sourceUrl: string) {
    actions.clearTrip();
    setProcessing(true);

    try {
      const pacing = getPacingState();
      const { data, error, response } = await apiClient.POST("/trips", {
        // One key per magic link the user submitted. Minted here rather than inside the
        // client so a retry of this same submission would carry the same one; minting it
        // per HTTP attempt would protect nothing (ADR-077).
        params: { header: { "Idempotency-Key": newIdempotencyKey() } },
        body: {
          sourceUrl,
          ...pacing,
          startDate: useTripStore.getState().startDate,
        },
      });

      if (error || !data) {
        reportApiError(response.status, error);
        setProcessing(false);
        setAccommodationScanning(false);
        return;
      }

      actions.setIsLocked(data.isLocked === true);
      actions.setTrip({
        id: data.id ?? "",
        title: getRandomTripName(),
        sourceUrl,
      });
      const importEvent = importEventForUrl(sourceUrl);
      if (importEvent) trackEvent(importEvent);
      trackEvent("trip_created", { source: importEvent ?? "url" });
      toast.success(t("planner.tripSavedToAccount"));
      router.push(`/trips/${data.id ?? ""}`);
    } catch (err) {
      if (isNetworkError(err)) {
        toast.error(t("errors.networkError"));
      } else {
        toast.error(t("errors.unexpectedError"));
      }
      setProcessing(false);
      setAccommodationScanning(false);
    }
  }

  async function handleGpxUpload(file: File) {
    actions.clearTrip();
    setProcessing(true);

    try {
      const pacing = getPacingState();
      const { data, error } = await uploadGpxFile(file, {
        ...pacing,
        startDate: useTripStore.getState().startDate,
      });

      if (error || !data) {
        toast.error(t("errors.gpxUploadFailed"));
        setProcessing(false);
        setAccommodationScanning(false);
        return;
      }

      actions.setTrip({
        id: data.id,
        title: data.title ?? file.name.replace(/\.gpx$/i, ""),
        sourceUrl: "",
      });
      trackEvent("import_gpx");
      trackEvent("trip_created", { source: "gpx" });
      toast.success(t("planner.tripSavedToAccount"));
      // Navigate to /trips/{id} like the magic-link flow (#729): the planner
      // re-hydrates from the detail endpoint and the async Mercure lifecycle
      // (route_parsed → stages_computed → preview) drives the wizard. Without
      // this the GPX flow stayed on /trips/new and the preview gate ("Lancer
      // l'analyse") was unreachable.
      router.push(`/trips/${data.id}`);
    } catch (err) {
      if (isNetworkError(err)) {
        toast.error(t("errors.networkError"));
      } else {
        toast.error(t("errors.unexpectedError"));
      }
      setProcessing(false);
      setAccommodationScanning(false);
    }
  }

  const [isShareModalOpen, setShareModalOpen] = useState(false);

  function handleShareTrip(): void {
    if (!tripId || !trip) return;
    setShareModalOpen(true);
  }

  async function handleApplyBatch() {
    const { pendingModifications } = useTripStore.getState();
    if (!tripId || pendingModifications.length === 0) return;

    setIsBatchApplying(true);
    try {
      const ok = await applyBatchRecompute(tripId, pendingModifications);
      if (ok) {
        actions.clearPendingModifications();
        setProcessing(true);
        setAccommodationScanning(true);
        // Mark all stages affected by pending modifications as recomputing.
        // The dependency rules are positional ("and every subsequent one"), so the
        // identifier is resolved against the current order and the markers are
        // stored back as identifiers.
        const stages = useTripStore.getState().stages;
        const affected = new Set<string>();
        for (const mod of pendingModifications) {
          if (mod.stageId !== null) {
            const at = stages.findIndex((s) => s.id === mod.stageId);
            if (at === -1) continue;
            if (mod.type === "distance") {
              // Distance recomputes the modified stage and every subsequent one
              // (mirrors ComputationDependencyResolver.resolve on the backend).
              for (const stage of stages.slice(at)) affected.add(stage.id);
            } else {
              for (const stage of stages.slice(at, at + 2)) {
                affected.add(stage.id);
              }
            }
          } else {
            // Trip-level modifications (dates, pacing) affect all stages
            for (const stage of stages) {
              affected.add(stage.id);
            }
          }
        }
        if (affected.size > 0) {
          actions.startStageRecomputation(Array.from(affected));
        }
      } else {
        toast.error(t("modificationQueue.failedApply"));
      }
    } catch {
      toast.error(t("modificationQueue.failedApply"));
    } finally {
      setIsBatchApplying(false);
    }
  }

  function handleCancelBatch() {
    actions.cancelAllModifications();
  }

  const firstStage = stages[0];
  const firstWeather = firstStage?.weather ?? null;
  const isWeatherLoading = isProcessing && stages.length > 0 && !firstWeather;

  return {
    trip,
    isLocked,
    outOfZone,
    totalDistance,
    totalElevation,
    totalElevationLoss,
    stages,
    startDate,
    endDate,
    isProcessing,
    firstWeather,
    isWeatherLoading,
    fatigueFactor,
    elevationPenalty,
    maxDistancePerDay,
    averageSpeed,
    ebikeMode,
    departureHour,
    enabledAccommodationTypes,
    handleMagicLink,
    handleGpxUpload,
    handleShareTrip,
    isShareModalOpen,
    setShareModalOpen,
    pendingModifications,
    isBatchApplying,
    handleApplyBatch,
    handleCancelBatch,
  };
}
