import { useCallback, useMemo } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { getDifficulty, stageDate } from '@btp/core';
import { EmptyState, LoadingState } from '../ui';
import { alertSegmentToCoords } from '../map/map-utils';
import { ElevationProfile } from './ElevationProfile';
import { useEditGate } from '../../hooks/use-edit-gate';
import { StageDataBlocks, notifyFailure } from './StageDataBlocks';
import { formatStageDate } from './roadbook-dates';
import { activeStageIndex, stageStats, surfaceShares } from './stage-detail';
import { StageDetailTopbar } from './StageDetailTopbar';
import { DayStrip } from './DayStrip';
import { StageMapPanel } from './StageMapPanel';
import { DifficultyPill, LocationsSection, Section, SurfaceBar } from './StageDetailSections';
import { StageStatsSection } from './StageStatsSection';
import { useTheme } from '../../theme';
import { useTripStore } from '../../store/trip-store';
import { useStageCursor } from '../../hooks/use-stage-cursor';
import { useStageDetail } from '../../hooks/use-stage-detail';
import { useTripMutations } from '../../hooks/use-trip-mutations';
import type { MutationFailure } from '../../store/gating';

// Full-screen detail for one stage (#1039), restyled to the Spike-UX mockup: a
// bounded prev/next topbar with a centered date/position title, a horizontal
// day-strip (rest days dashed, the active day accented), the stage map + focused
// elevation profile, then themed section cards (locations / stats with inline
// distance edit / difficulty / surfaces) and the shared per-day data blocks
// (weather / alerts / events / accommodation / supply / POI). Reads straight from
// the live store; the stage index is local state so prev/next stays on one
// mounted screen (no navigation stacking, no SSE re-subscribe).
export function StageDetailView({ initialStageId }: { initialStageId: string }) {
  const { t, i18n } = useTranslation();
  const theme = useTheme();
  const stages = useTripStore((s) => s.stages);
  const startDate = useTripStore((s) => s.startDate);
  const loading = useTripStore((s) => s.loading);
  const tripId = useTripStore((s) => s.tripId);
  const title = useTripStore((s) => s.title);
  const routingGate = useEditGate(true);
  // The screen is addressed by identity (a deep link, a notification), but paging
  // through the roadbook is positional, so the identity is resolved once on mount
  // and the cursor stays an index from there. Resolving against the store means a
  // cached trip answers offline too.
  const initialIndex = useTripStore((s) =>
    s.stages.findIndex((stage) => stage.id === initialStageId),
  );
  const count = stages.length;
  const {
    stageIndex,
    highlightedSegment,
    selectedPoi,
    goTo,
    setHighlightedSegment,
    setSelectedPoi,
  } = useStageCursor(initialIndex, count);
  // The summary omits geometry (ADR-057); pull just this stage's detail in for
  // the mini-map + profile (not the whole route).
  useStageDetail(stageIndex);

  const onFailure = useCallback((reason: MutationFailure) => notifyFailure(t, reason), [t]);
  const mutations = useTripMutations(tripId ?? '', onFailure);

  const stage = stages[stageIndex];
  const profileIndex = useMemo(() => activeStageIndex(stages, stageIndex), [stages, stageIndex]);

  if (!stage) {
    return loading ? <LoadingState /> : <EmptyState title={t('trip.stageDetail.notFound')} />;
  }

  const day = stage.dayNumber ?? stageIndex + 1;
  const date = stageDate(startDate, day);
  const heading = date ? formatStageDate(date, i18n.language) : t('trip.day', { day });
  const surfaces = surfaceShares(stage);

  // A distance edit and a POI added as a route waypoint both reroute the stage
  // via Valhalla, so they share the routing gate. A rest day has no route to
  // reroute, yet its own point(s) still render on this map (stageSegments is
  // built directly, not via buildStageLines), so exclude it explicitly:
  // otherwise a POI tap on a rest day would fire addPoiWaypoint.
  const canReroute = tripId !== null && routingGate === null && !stage.isRestDay;

  return (
    // The manual-accommodation form (AccommodationBlock) sits deep in this
    // scroll; without a KeyboardAvoidingView the keyboard covers its fields
    // and the "Ajouter" button on both platforms (#1171). `keyboardShouldPersistTaps`
    // lets the still-visible "Ajouter"/"Annuler" buttons register a tap
    // without the keyboard eating it first.
    <KeyboardAvoidingView
      style={styles.fill}
      // Android already resizes the window (Expo default softwareKeyboardLayoutMode
      // = adjustResize), so a `behavior` there would double-compensate; only iOS
      // needs padding. keyboardShouldPersistTaps on the ScrollView covers both.
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
    >
      <ScrollView
        style={{ backgroundColor: theme.colors.background }}
        contentContainerStyle={{ paddingBottom: theme.spacing.xl }}
        keyboardShouldPersistTaps="handled"
      >
        <StageDetailTopbar
          heading={heading}
          isRestDay={Boolean(stage.isRestDay)}
          index={stageIndex}
          count={count}
          onNavigate={goTo}
          tripId={tripId}
          tripTitle={title ?? t('trip.title')}
          stageId={stage.id}
          day={day}
        />

        <DayStrip stages={stages} startDate={startDate} activeIndex={stageIndex} onSelect={goTo} />

        <StageMapPanel
          stage={stage}
          highlightedSegment={highlightedSegment}
          selectedPoi={selectedPoi}
          canReroute={canReroute}
          onSelectPoi={setSelectedPoi}
          onAddPoi={(poi) => {
            void mutations.addPoiWaypoint(stageIndex, poi.lat, poi.lon);
            setSelectedPoi(null);
          }}
        />

        <View style={{ padding: theme.spacing.base }}>
          {profileIndex === null ? (
            // A rest day has no geometry/elevation of its own: rendering the
            // profile with a null focus would draw the WHOLE trip. Show a
            // placeholder instead (bug #1039).
            <EmptyState title={t('trip.stageDetail.restNoProfile')} />
          ) : (
            <ElevationProfile stages={stages} focusedStageIndex={profileIndex} onHover={() => {}} />
          )}
        </View>

        <View
          style={{
            paddingHorizontal: theme.spacing.base,
            gap: theme.spacing.md,
          }}
        >
          <LocationsSection
            departure={stage.startLabel ?? '?'}
            arrival={stage.endLabel ?? stage.label ?? '?'}
          />

          <StageStatsSection
            key={stageIndex}
            stats={stageStats(stage)}
            day={day}
            editable={canReroute}
            onCommitDistance={(km) => void mutations.updateStageDistance(stageIndex, km)}
          />

          {!stage.isRestDay ? (
            <Section title={t('trip.stageDetail.sectionDifficulty')}>
              <DifficultyPill level={getDifficulty(stage.distance, stage.elevation)} />
            </Section>
          ) : null}

          {surfaces.length > 0 ? (
            <Section title={t('trip.stageDetail.sectionSurfaces')}>
              <SurfaceBar surfaces={surfaces} />
            </Section>
          ) : null}

          <StageDataBlocks
            stage={stage}
            stageIndex={stageIndex}
            onAlertNavigate={(segments) =>
              setHighlightedSegment(
                segments.length > 0 ? alertSegmentToCoords(segments[0]) : undefined,
              )
            }
          />
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1 },
});
