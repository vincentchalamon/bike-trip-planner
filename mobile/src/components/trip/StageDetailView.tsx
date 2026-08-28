import { type ReactNode, useCallback, useEffect, useMemo, useState } from 'react';
import {
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  View,
} from 'react-native';
import { useTranslation } from 'react-i18next';
import { getDifficulty, type Difficulty } from '@btp/core';
import { EmptyState, LoadingState } from '../ui';
import {
  ArrowLeft,
  Check,
  ChevronRight,
  Flag,
  Gauge,
  MapPin,
  Mountain,
  Pencil,
  Route,
  X,
} from '../ui/icons';
import { TripMap } from '../TripMap';
import { PoiWaypointPopover } from './PoiWaypointPopover';
import {
  alertSegmentToCoords,
  collectMarkers,
  type MapMarker,
} from '../map/map-utils';
import { stageColor } from '../map/stage-colors';
import { ElevationProfile } from './ElevationProfile';
import { ExportButton } from './ExportButton';
import { StageDataBlocks, notifyFailure } from './StageDataBlocks';
import { formatStageDate, stageDateFor } from './roadbook-dates';
import {
  activeStageIndex,
  clampIndex,
  hasNextStage,
  hasPrevStage,
  stageGeometryCoords,
  stageStats,
  surfaceShares,
} from './stage-detail';
import { useTheme } from '../../theme';
import { useTripStore } from '../../store/trip-store';
import { useStageDetail } from '../../hooks/use-stage-detail';
import { useOfflineStore } from '../../store/offline-store';
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
export function StageDetailView({ initialIndex }: { initialIndex: number }) {
  const { t, i18n } = useTranslation();
  const theme = useTheme();
  const stages = useTripStore((s) => s.stages);
  const startDate = useTripStore((s) => s.startDate);
  const loading = useTripStore((s) => s.loading);
  const tripId = useTripStore((s) => s.tripId);
  const title = useTripStore((s) => s.title);
  const isLocked = useTripStore((s) => s.isLocked);
  const outOfZone = useTripStore((s) => s.outOfZone);
  const isOnline = useOfflineStore((s) => s.isOnline);
  const apiReachable = useOfflineStore((s) => s.apiReachable);
  const [index, setIndex] = useState(initialIndex);
  // Stretch highlighted by an alert `navigate` action ([lon, lat] for the map).
  const [highlightedSegment, setHighlightedSegment] = useState<
    [number, number][] | undefined
  >(undefined);
  const [editingDistance, setEditingDistance] = useState(false);
  const [draft, setDraft] = useState('');
  // POI marker tapped on the stage map → its "add to itinerary" popover (#1179).
  const [selectedPoi, setSelectedPoi] = useState<MapMarker | null>(null);

  // Keep the index valid as stages hydrate / change under us.
  const count = stages.length;
  const safeIndex = clampIndex(index, count);
  // The summary omits geometry (ADR-057); pull just this stage's detail in for
  // the mini-map + profile (not the whole route).
  useStageDetail(safeIndex);
  useEffect(() => {
    if (safeIndex !== index) setIndex(safeIndex);
  }, [safeIndex, index]);

  // Drop a stale highlight / open editor when the stage changes: both belong to
  // another stage.
  useEffect(() => {
    setHighlightedSegment(undefined);
    setEditingDistance(false);
    setDraft('');
    setSelectedPoi(null);
  }, [safeIndex]);

  const onFailure = useCallback(
    (reason: MutationFailure) => notifyFailure(t, reason),
    [t],
  );
  const mutations = useTripMutations(tripId ?? '', onFailure);

  const stage = stages[safeIndex];

  const coordinates = useMemo(
    () => (stage ? stageGeometryCoords(stage) : []),
    [stage],
  );
  // One colored line for this stage, keyed on its dayNumber, so its color
  // matches the trip map's stage coloring (see stageColor). Built directly
  // (not via buildStageLines, which drops rest days for the multi-stage
  // overview map) so a rest day's own point(s) still render here.
  const stageSegments = useMemo(
    () =>
      stage && coordinates.length >= 2
        ? [{ color: stageColor(stage.dayNumber ?? 0), coordinates }]
        : [],
    [stage, coordinates],
  );
  const markers = useMemo(() => (stage ? collectMarkers([stage]) : []), [stage]);
  const profileIndex = useMemo(
    () => activeStageIndex(stages, safeIndex),
    [stages, safeIndex],
  );

  if (!stage) {
    return loading ? (
      <LoadingState />
    ) : (
      <EmptyState title={t('trip.stageDetail.notFound')} />
    );
  }

  const date = stageDateFor(startDate, stage.dayNumber ?? safeIndex + 1);
  const heading = date
    ? formatStageDate(date, i18n.language)
    : t('trip.day', { day: stage.dayNumber ?? safeIndex + 1 });
  const stats = stageStats(stage);
  const surfaces = surfaceShares(stage);
  const day = stage.dayNumber ?? safeIndex + 1;

  const canEditDistance =
    tripId !== null &&
    !isLocked &&
    isOnline &&
    apiReachable &&
    !outOfZone &&
    !stage.isRestDay;

  // Adding a POI as a route waypoint reroutes the stage via Valhalla, so it needs
  // the same live-write conditions as a distance edit (and the trip zone). A rest
  // day has no route to reroute, yet its own point(s) still render on this map
  // (stageSegments is built directly, not via buildStageLines), so exclude it
  // explicitly — otherwise a POI tap on a rest day would fire addPoiWaypoint.
  const canAddWaypoint =
    tripId !== null && !isLocked && isOnline && apiReachable && !outOfZone && !stage.isRestDay;

  function startEditDistance(): void {
    setDraft(String(stats.distanceKm));
    setEditingDistance(true);
  }

  function commitDistance(): void {
    const km = Number(draft.replace(',', '.'));
    if (!Number.isFinite(km) || km <= 0) return;
    setEditingDistance(false);
    void mutations.updateStageDistance(safeIndex, km);
  }

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
      <View
        style={[
          styles.topbar,
          {
            borderBottomColor: theme.colors.border,
            paddingHorizontal: theme.spacing.base,
            paddingVertical: theme.spacing.sm,
            gap: theme.spacing.xs,
          },
        ]}
      >
        <IconButton
          a11yLabel={t('trip.stageDetail.prev')}
          disabled={!hasPrevStage(safeIndex)}
          onPress={() => setIndex(safeIndex - 1)}
        >
          <ArrowLeft
            color={
              hasPrevStage(safeIndex)
                ? theme.colors.foreground
                : theme.colors.mutedIcon
            }
            size={20}
          />
        </IconButton>
        <View style={styles.titleWrap}>
          <Text
            numberOfLines={1}
            style={{
              color: theme.colors.foreground,
              fontFamily: theme.fonts.serif,
              fontSize: 18,
              textAlign: 'center',
            }}
          >
            {heading}
            {stage.isRestDay ? ` · ${t('trip.rest')}` : ''}
          </Text>
          <Text
            style={{
              color: theme.colors.mutedForeground,
              fontFamily: theme.fonts.sansMedium,
              fontSize: 12,
              textAlign: 'center',
            }}
          >
            {t('trip.stageDetail.position', {
              current: safeIndex + 1,
              total: count,
            })}
          </Text>
        </View>
        <IconButton
          a11yLabel={t('trip.stageDetail.next')}
          disabled={!hasNextStage(safeIndex, count)}
          onPress={() => setIndex(safeIndex + 1)}
        >
          <ChevronRight
            color={
              hasNextStage(safeIndex, count)
                ? theme.colors.foreground
                : theme.colors.mutedIcon
            }
            size={22}
          />
        </IconButton>
        {tripId ? (
          <ExportButton
            tripId={tripId}
            tripTitle={title ?? t('trip.title')}
            stage={{ dayNumber: day }}
          />
        ) : null}
      </View>

      <DayStrip
        stages={stages}
        startDate={startDate}
        activeIndex={safeIndex}
        onSelect={setIndex}
      />

      <View style={{ height: 220 }}>
        {coordinates.length > 0 ? (
          <>
            <TripMap
              stageSegments={stageSegments}
              markers={markers}
              highlightedSegment={highlightedSegment}
              onSelectPoi={canAddWaypoint ? setSelectedPoi : undefined}
            />
            {selectedPoi ? (
              <PoiWaypointPopover
                poi={selectedPoi}
                disabled={!canAddWaypoint}
                onAdd={() => {
                  void mutations.addPoiWaypoint(
                    safeIndex,
                    selectedPoi.lat,
                    selectedPoi.lon,
                  );
                  setSelectedPoi(null);
                }}
                onClose={() => setSelectedPoi(null)}
              />
            ) : null}
          </>
        ) : (
          <View style={{ flex: 1 }}>
            <EmptyState title={t('trip.mapEmpty')} />
          </View>
        )}
      </View>

      <View style={{ padding: theme.spacing.base }}>
        {profileIndex === null ? (
          // A rest day has no geometry/elevation of its own: rendering the
          // profile with a null focus would draw the WHOLE trip. Show a
          // placeholder instead (bug #1039).
          <EmptyState title={t('trip.stageDetail.restNoProfile')} />
        ) : (
          <ElevationProfile
            stages={stages}
            focusedStageIndex={profileIndex}
            onHover={() => {}}
          />
        )}
      </View>

      <View
        style={{
          paddingHorizontal: theme.spacing.base,
          gap: theme.spacing.md,
        }}
      >
        <Section title={t('trip.stageDetail.sectionLocations')}>
          <View style={{ gap: theme.spacing.sm }}>
            <LocationRow
              icon={<MapPin color={theme.colors.accentBrand} size={16} />}
              label={t('trip.stageDetail.departure')}
              place={stage.startLabel ?? '?'}
            />
            <LocationRow
              icon={<Flag color={theme.colors.mutedIcon} size={16} />}
              label={t('trip.stageDetail.arrival')}
              place={stage.endLabel ?? stage.label ?? '?'}
            />
          </View>
        </Section>

        <Section title={t('trip.stageDetail.sectionStats')}>
          <View style={styles.statsRow}>
            <DistanceStat
              value={t('trip.stageDetail.distanceValue', {
                value: stats.distanceKm,
              })}
              editable={canEditDistance}
              a11yLabel={t('trip.edit.editDistanceA11y', { day })}
              onEdit={startEditDistance}
            />
            <StatCell
              icon={<Mountain color={theme.colors.mutedIcon} size={16} />}
              label={t('trip.stageDetail.ascent')}
              value={t('trip.stageDetail.elevationValue', {
                value: stats.elevationGain,
              })}
            />
            <StatCell
              icon={<Mountain color={theme.colors.mutedIcon} size={16} />}
              label={t('trip.stageDetail.descent')}
              value={t('trip.stageDetail.elevationValue', {
                value: stats.elevationLoss,
              })}
            />
          </View>
          {editingDistance ? (
            <View style={styles.editRow}>
              <TextInput
                accessibilityLabel={t('trip.edit.editDistanceA11y', { day })}
                value={draft}
                onChangeText={setDraft}
                keyboardType="numeric"
                autoFocus
                placeholder={t('trip.edit.distancePlaceholder')}
                placeholderTextColor={theme.colors.mutedForeground}
                onSubmitEditing={commitDistance}
                style={{
                  flex: 1,
                  height: 40,
                  borderWidth: 1,
                  borderColor: theme.colors.input,
                  borderRadius: theme.radius.md,
                  paddingHorizontal: theme.spacing.md,
                  color: theme.colors.foreground,
                  backgroundColor: theme.colors.surface,
                  fontFamily: theme.fonts.mono,
                  fontSize: 15,
                }}
              />
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={t('trip.edit.saveA11y')}
                onPress={commitDistance}
                hitSlop={6}
                style={{ padding: theme.spacing.sm }}
              >
                <Check color={theme.colors.brandFill} size={22} />
              </Pressable>
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={t('trip.edit.cancelA11y')}
                onPress={() => setEditingDistance(false)}
                hitSlop={6}
                style={{ padding: theme.spacing.sm }}
              >
                <X color={theme.colors.mutedForeground} size={22} />
              </Pressable>
            </View>
          ) : null}
        </Section>

        {!stage.isRestDay ? (
          <Section title={t('trip.stageDetail.sectionDifficulty')}>
            <DifficultyPill
              level={getDifficulty(stage.distance, stage.elevation)}
            />
          </Section>
        ) : null}

        {surfaces.length > 0 ? (
          <Section title={t('trip.stageDetail.sectionSurfaces')}>
            <SurfaceBar surfaces={surfaces} />
          </Section>
        ) : null}

        <StageDataBlocks
          stage={stage}
          stageIndex={safeIndex}
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

// A borderless square tap target for the topbar chevrons. Stays in the tree when
// disabled (accessibilityRole/State preserved) so nav bounds are testable.
function IconButton({
  a11yLabel,
  disabled = false,
  onPress,
  children,
}: {
  a11yLabel: string;
  disabled?: boolean;
  onPress: () => void;
  children: ReactNode;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={a11yLabel}
      accessibilityState={{ disabled }}
      disabled={disabled}
      onPress={onPress}
      hitSlop={8}
      style={{ padding: 6, opacity: disabled ? 0.4 : 1 }}
    >
      {children}
    </Pressable>
  );
}

// Horizontal, scrollable day-strip: one chip per stage (its short date, or a
// "Jour N" fallback). The active day is accented, rest days are dashed + muted.
function DayStrip({
  stages,
  startDate,
  activeIndex,
  onSelect,
}: {
  stages: { dayNumber?: number | null; isRestDay?: boolean }[];
  startDate: string | null;
  activeIndex: number;
  onSelect: (index: number) => void;
}) {
  const theme = useTheme();
  const { t, i18n } = useTranslation();
  return (
    <ScrollView
      horizontal
      showsHorizontalScrollIndicator={false}
      contentContainerStyle={{
        paddingHorizontal: theme.spacing.base,
        paddingVertical: theme.spacing.sm,
        gap: theme.spacing.sm,
      }}
    >
      {stages.map((s, i) => {
        const active = i === activeIndex;
        const rest = Boolean(s.isRestDay);
        const dayNum = s.dayNumber ?? i + 1;
        const date = stageDateFor(startDate, dayNum);
        const label = date
          ? formatStageDate(date, i18n.language)
          : t('trip.day', { day: dayNum });
        return (
          <Pressable
            key={i}
            accessibilityRole="button"
            accessibilityState={{ selected: active }}
            onPress={() => onSelect(i)}
            // ~28pt tall (padding xs + 13pt text), under the 44pt minimum.
            // Vertical-only: chips sit side by side in a horizontal
            // ScrollView, so a horizontal hitSlop would steal taps from the
            // next/previous chip (#1233 a11y).
            hitSlop={{ top: 9, bottom: 9 }}
            style={{
              paddingHorizontal: theme.spacing.md,
              paddingVertical: theme.spacing.xs,
              borderRadius: theme.radius.full,
              borderWidth: 1,
              borderStyle: rest ? 'dashed' : 'solid',
              borderColor: active ? theme.colors.accentBrand : theme.colors.border,
              backgroundColor: active ? theme.colors.accentBrand : theme.colors.card,
            }}
          >
            <Text
              style={{
                color: active
                  ? theme.colors.primaryForeground
                  : rest
                    ? theme.colors.mutedForeground
                    : theme.colors.foreground,
                fontFamily: theme.fonts.sansMedium,
                fontSize: 13,
              }}
            >
              {label}
            </Text>
          </Pressable>
        );
      })}
    </ScrollView>
  );
}

// Uppercase muted section label + a bordered card body.
function Section({ title, children }: { title: string; children: ReactNode }) {
  const theme = useTheme();
  return (
    <View style={{ gap: theme.spacing.sm }}>
      <Text
        style={{
          color: theme.colors.mutedForeground,
          fontFamily: theme.fonts.sansSemibold,
          fontSize: 11,
          letterSpacing: 1,
          textTransform: 'uppercase',
        }}
      >
        {title}
      </Text>
      <View
        style={{
          backgroundColor: theme.colors.card,
          borderColor: theme.colors.border,
          borderWidth: StyleSheet.hairlineWidth,
          borderRadius: theme.radius.xl,
          padding: theme.spacing.base,
        }}
      >
        {children}
      </View>
    </View>
  );
}

function LocationRow({
  icon,
  label,
  place,
}: {
  icon: ReactNode;
  label: string;
  place: string;
}) {
  const theme = useTheme();
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', gap: theme.spacing.sm }}>
      {icon}
      <Text
        style={{
          color: theme.colors.mutedForeground,
          fontFamily: theme.fonts.sansMedium,
          fontSize: 12,
          width: 64,
        }}
      >
        {label}
      </Text>
      <Text
        style={{
          color: theme.colors.foreground,
          fontFamily: theme.fonts.sans,
          fontSize: 15,
          flex: 1,
        }}
      >
        {place}
      </Text>
    </View>
  );
}

function StatCell({
  icon,
  label,
  value,
}: {
  icon: ReactNode;
  label: string;
  value: string;
}) {
  const theme = useTheme();
  return (
    <View
      style={[
        styles.statCell,
        { backgroundColor: theme.colors.secondary, borderColor: theme.colors.border },
      ]}
    >
      <View style={styles.statHead}>
        {icon}
        <Text
          style={{
            color: theme.colors.mutedForeground,
            fontFamily: theme.fonts.sansMedium,
            fontSize: 12,
          }}
        >
          {label}
        </Text>
      </View>
      <Text
        style={{
          color: theme.colors.foreground,
          fontFamily: theme.fonts.mono,
          fontSize: 15,
        }}
      >
        {value}
      </Text>
    </View>
  );
}

// The distance stat cell, tappable into an inline editor when editable (#1045
// distance-edit affordance mirrors the roadbook StageCard).
function DistanceStat({
  value,
  editable,
  a11yLabel,
  onEdit,
}: {
  value: string;
  editable: boolean;
  a11yLabel: string;
  onEdit: () => void;
}) {
  const theme = useTheme();
  const { t } = useTranslation();
  return (
    <Pressable
      accessibilityRole={editable ? 'button' : undefined}
      accessibilityLabel={editable ? a11yLabel : undefined}
      disabled={!editable}
      onPress={editable ? onEdit : undefined}
      style={[
        styles.statCell,
        { backgroundColor: theme.colors.secondary, borderColor: theme.colors.border },
      ]}
    >
      <View style={styles.statHead}>
        <Route color={theme.colors.mutedIcon} size={16} />
        <Text
          style={{
            color: theme.colors.mutedForeground,
            fontFamily: theme.fonts.sansMedium,
            fontSize: 12,
            flex: 1,
          }}
        >
          {t('trip.stageDetail.distance')}
        </Text>
        {editable ? <Pencil color={theme.colors.accentBrand} size={14} /> : null}
      </View>
      <Text
        style={{
          color: theme.colors.foreground,
          fontFamily: theme.fonts.mono,
          fontSize: 15,
        }}
      >
        {value}
      </Text>
    </Pressable>
  );
}

function DifficultyPill({ level }: { level: Difficulty }) {
  const theme = useTheme();
  const { t } = useTranslation();
  const label = {
    easy: t('trip.stageDetail.difficultyEasy'),
    medium: t('trip.stageDetail.difficultyMedium'),
    hard: t('trip.stageDetail.difficultyHard'),
  }[level];
  return (
    <View
      style={{
        flexDirection: 'row',
        alignItems: 'center',
        alignSelf: 'flex-start',
        gap: theme.spacing.xs,
        backgroundColor: theme.colors.accentSoft,
        borderRadius: theme.radius.full,
        paddingHorizontal: theme.spacing.md,
        paddingVertical: theme.spacing.xs,
      }}
    >
      <Gauge color={theme.colors.accentInk} size={14} />
      <Text
        style={{
          color: theme.colors.accentInk,
          fontFamily: theme.fonts.sansMedium,
          fontSize: 13,
        }}
      >
        {label}
      </Text>
    </View>
  );
}

// A proportional surface-mix bar + legend. Colours cycle through themed tokens
// (no green token exists, so the mockup's semantic hues are approximated with
// the accent / muted palette).
function SurfaceBar({
  surfaces,
}: {
  surfaces: { surface: string; percent: number }[];
}) {
  const theme = useTheme();
  const palette = [
    theme.colors.accentBrand,
    theme.colors.mutedForeground,
    theme.colors.mutedIcon,
    theme.colors.border,
  ];
  const color = (i: number): string => palette[i % palette.length]!;
  return (
    <View style={{ gap: theme.spacing.sm }}>
      <View
        style={{
          flexDirection: 'row',
          height: 10,
          borderRadius: theme.radius.full,
          overflow: 'hidden',
        }}
      >
        {surfaces.map((s, i) => (
          <View
            key={s.surface}
            style={{ flex: s.percent, backgroundColor: color(i) }}
          />
        ))}
      </View>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: theme.spacing.md }}>
        {surfaces.map((s, i) => (
          <View
            key={s.surface}
            style={{ flexDirection: 'row', alignItems: 'center', gap: theme.spacing.xs }}
          >
            <View
              style={{
                width: 10,
                height: 10,
                borderRadius: 3,
                backgroundColor: color(i),
              }}
            />
            <Text
              style={{
                color: theme.colors.mutedForeground,
                fontFamily: theme.fonts.mono,
                fontSize: 12,
              }}
            >
              {s.surface} {s.percent}%
            </Text>
          </View>
        ))}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  fill: { flex: 1 },
  topbar: {
    flexDirection: 'row',
    alignItems: 'center',
    borderBottomWidth: StyleSheet.hairlineWidth,
  },
  titleWrap: { flex: 1, alignItems: 'center' },
  statsRow: { flexDirection: 'row', gap: 8 },
  editRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    marginTop: 8,
  },
  statCell: {
    flex: 1,
    gap: 4,
    borderWidth: StyleSheet.hairlineWidth,
    borderRadius: 12,
    paddingHorizontal: 12,
    paddingVertical: 10,
  },
  statHead: { flexDirection: 'row', alignItems: 'center', gap: 6 },
});
