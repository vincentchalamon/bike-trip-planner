import { type ReactNode, useEffect, useState } from 'react';
import { Pressable, Text, TextInput, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import type { StageData } from '@btp/core';
import {
  AlertTriangle,
  Check,
  CloudSun,
  Pencil,
  Trash2,
  X,
} from '../ui/icons';
import { useTheme } from '../../theme';
import { formatStageDate } from './roadbook-dates';

// A per-stage identity signature used both as the roadbook FlatList key and as
// the reset trigger for a StageCard's local edit state. Stages carry no stable
// id and `dayNumber` is renumbered on every structural edit, so a pure index /
// dayNumber key lets React reuse a row instance for a *different* stage after an
// insert / rest-day / delete shifts positions — a stale open distance editor
// would then commit onto the wrong stage. Folding the endpoints in makes the key
// change whenever a row switches to a different underlying stage (the placeholder
// spans a single boundary point, so it never collides with the stage it
// displaces), forcing React to remount that row and tear down the stale editor.
// dayNumber keeps the key unique across the list (#1044 review).
export function stageKey(stage: StageData): string {
  const s = stage.startPoint;
  const e = stage.endPoint;
  return `${stage.dayNumber}|${stage.isRestDay ? 'r' : 's'}|${s.lat},${s.lon}|${e.lat},${e.lon}`;
}

interface StageCardProps {
  stage: StageData;
  index: number;
  // A started trip is read-only (backend 423): every edit action is hidden.
  locked: boolean;
  // Route outside the covered area: rerouting edits (+stage / distance) are
  // hidden; a rest day and a delete (no Valhalla reroute) stay available.
  outOfZone?: boolean;
  // A structural mutation for this row is in flight (optimistic apply + API
  // round-trip): every edit control is disabled so a rapid double-tap cannot
  // dispatch the same insert/edit twice (#1044 review).
  busy?: boolean;
  onDelete: (index: number) => void;
  // Commit an edited distance (km) for this stage; the backend re-splits and
  // streams the authoritative stages over SSE.
  onEditDistance?: (index: number, distanceKm: number) => void;
  // Calendar day of the stage (YYYY-MM-DD, UTC), or null when the trip has no
  // start date — the card then falls back to "Jour N".
  date?: string | null;
  // True when `date` is today on an ongoing trip: shows the "Aujourd'hui"
  // pastille.
  isToday?: boolean;
  // Tap-through to the full-screen stage detail (#1039). The roadbook row is a
  // summary only (ADR-057 / #1105); the per-day detail lives on the detail screen.
  onPress?: (index: number) => void;
  // Transient diff-highlight after a destructive recompute (#1046): tints the
  // row so the rider sees which days the re-split changed.
  highlighted?: boolean;
}

// A compact pill action used by the inline edit footer.
function EditChip({
  icon,
  label,
  onPress,
  a11yLabel,
  disabled = false,
}: {
  icon: ReactNode;
  label: string;
  onPress: () => void;
  a11yLabel: string;
  disabled?: boolean;
}) {
  const theme = useTheme();
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={a11yLabel}
      accessibilityState={{ disabled }}
      disabled={disabled}
      onPress={onPress}
      hitSlop={6}
      style={{
        flexDirection: 'row',
        alignItems: 'center',
        gap: theme.spacing.xs,
        borderWidth: 1,
        borderColor: theme.colors.accentBrand,
        backgroundColor: theme.colors.accentSoft,
        borderRadius: theme.radius.full,
        paddingHorizontal: theme.spacing.md,
        paddingVertical: theme.spacing.xs,
        opacity: disabled ? 0.4 : 1,
      }}
    >
      {icon}
      <Text
        style={{
          color: theme.colors.accentInk,
          fontFamily: theme.fonts.sansMedium,
          fontSize: 13,
        }}
      >
        {label}
      </Text>
    </Pressable>
  );
}

// One roadbook row: the stage date (or "Jour N" fallback) + rest tag, start →
// end labels, distance / elevation, a "today" pastille on the current day, and
// an inline edit footer (＋étape / ＋repos / distance / delete) when the trip is
// still editable (#1044). Rendered by RoadbookView; #1039 wires the tap-through.
export function StageCard({
  stage,
  index,
  locked,
  outOfZone = false,
  busy = false,
  onDelete,
  onEditDistance,
  date = null,
  isToday = false,
  onPress,
  highlighted = false,
}: StageCardProps) {
  const { t, i18n } = useTranslation();
  const theme = useTheme();
  const [editingDistance, setEditingDistance] = useState(false);
  const [draft, setDraft] = useState('');
  const day = stage.dayNumber ?? index + 1;
  const heading = date
    ? formatStageDate(date, i18n.language)
    : t('trip.day', { day: stage.dayNumber ?? '?' });
  // Route title: both endpoints when known, else whichever single label is
  // resolved, else fall back to the day label — never the "? → ?" placeholder
  // when nothing is resolved yet.
  const routeLabel =
    stage.startLabel && stage.endLabel
      ? `${stage.startLabel} → ${stage.endLabel}`
      : (stage.startLabel ??
        stage.endLabel ??
        stage.label ??
        t('trip.day', { day }));

  // Belt-and-suspenders to the stable FlatList key: if the underlying stage this
  // row renders changes identity (a position shift reused the instance, or SSE
  // reconciled the stage), drop any open distance editor so it can never commit
  // a stale draft onto a different stage than the one it was opened on (#1044).
  const key = stageKey(stage);
  useEffect(() => {
    setEditingDistance(false);
    setDraft('');
  }, [key]);

  // Distance re-splitting reroutes → hidden out of zone and on a rest day (0 km).
  const canEditDistance =
    !locked && !!onEditDistance && !stage.isRestDay && !outOfZone;
  const showFooter = canEditDistance;

  function startEditDistance(): void {
    setDraft(String(Math.round(stage.distance ?? 0)));
    setEditingDistance(true);
  }

  function commitDistance(): void {
    // A mutation for this row is already in flight: swallow the tap so a
    // double-submit cannot dispatch a second update (#1044 review).
    if (busy) return;
    const km = Number(draft.replace(',', '.'));
    // Keep the editor open on an invalid/empty value so the edit is not lost
    // silently; only close + commit a finite, positive distance.
    if (!Number.isFinite(km) || km <= 0) return;
    setEditingDistance(false);
    onEditDistance?.(index, km);
  }

  const alertCount = stage.alerts?.length ?? 0;

  return (
    <View
      accessibilityLabel={highlighted ? t('trip.diffChanged') : undefined}
      style={{
        marginHorizontal: theme.spacing.base,
        marginBottom: theme.spacing.md,
        borderWidth: 1,
        borderColor: theme.colors.border,
        borderRadius: theme.radius.lg,
        overflow: 'hidden',
        backgroundColor: highlighted ? theme.colors.accentSoft : theme.colors.card,
      }}
    >
      <View
        style={{
          flexDirection: 'row',
          alignItems: 'flex-start',
          paddingVertical: theme.spacing.md,
          paddingHorizontal: theme.spacing.md,
          gap: theme.spacing.sm,
        }}
      >
      <Pressable
        disabled={!onPress}
        onPress={onPress ? () => onPress(index) : undefined}
        accessibilityRole={onPress ? 'button' : undefined}
        accessibilityLabel={
          onPress ? t('trip.openStageA11y', { day }) : undefined
        }
        style={{ flex: 1, flexDirection: 'row', gap: theme.spacing.md }}
      >
        {/* Date badge */}
        <View
          style={{
            minWidth: 56,
            alignItems: 'center',
            backgroundColor: theme.colors.accentSoft,
            borderRadius: theme.radius.md,
            paddingHorizontal: theme.spacing.sm,
            paddingVertical: theme.spacing.xs,
          }}
        >
          <Text
            style={{
              color: theme.colors.accentInk,
              fontFamily: theme.fonts.sansSemibold,
              fontSize: 13,
              textAlign: 'center',
            }}
          >
            {heading}
          </Text>
          {stage.isRestDay ? (
            <Text
              style={{
                color: theme.colors.accentInk,
                fontFamily: theme.fonts.sansMedium,
                fontSize: 11,
                marginTop: 2,
              }}
            >
              {t('trip.rest')}
            </Text>
          ) : null}
        </View>
        {/* Title + route + KPIs */}
        <View style={{ flex: 1 }}>
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: theme.spacing.sm }}>
            <Text
              style={{
                color: theme.colors.foreground,
                fontFamily: theme.fonts.sansSemibold,
                fontSize: 16,
                flexShrink: 1,
              }}
            >
              {routeLabel}
            </Text>
            {isToday ? (
              <Text
                accessibilityLabel={t('trip.today')}
                style={{
                  color: theme.colors.accentInk,
                  backgroundColor: theme.colors.accentSoft,
                  fontFamily: theme.fonts.sansMedium,
                  fontSize: 11,
                  overflow: 'hidden',
                  borderRadius: theme.radius.full,
                  paddingHorizontal: theme.spacing.sm,
                  paddingVertical: 2,
                }}
              >
                {t('trip.today')}
              </Text>
            ) : null}
          </View>
          <Text
            style={{
              color: theme.colors.mutedForeground,
              fontFamily: theme.fonts.mono,
              fontSize: 13,
              marginTop: 4,
            }}
          >
            {t('trip.stageMeta', {
              distance: Math.round(stage.distance ?? 0),
              elevation: Math.round(stage.elevation ?? 0),
            })}
          </Text>
        </View>
      </Pressable>
      {/* Weather + alerts */}
      <View style={{ alignItems: 'flex-end', gap: theme.spacing.xs }}>
        {stage.weather ? (
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: theme.spacing.xs }}>
            <CloudSun color={theme.colors.mutedIcon} size={16} />
            <Text
              style={{
                color: theme.colors.mutedForeground,
                fontFamily: theme.fonts.sansMedium,
                fontSize: 13,
              }}
            >
              {t('trip.summary.degrees', {
                value: Math.round(stage.weather.tempMax),
              })}
            </Text>
          </View>
        ) : null}
        {alertCount > 0 ? (
          <View
            style={{
              flexDirection: 'row',
              alignItems: 'center',
              gap: theme.spacing.xs,
              backgroundColor: theme.colors.accentSoft,
              borderRadius: theme.radius.full,
              paddingHorizontal: theme.spacing.sm,
              paddingVertical: 2,
            }}
          >
            <AlertTriangle color={theme.colors.accentBrand} size={13} />
            <Text
              style={{
                color: theme.colors.accentInk,
                fontFamily: theme.fonts.sansMedium,
                fontSize: 12,
              }}
            >
              {alertCount}
            </Text>
          </View>
        ) : null}
      </View>
      {!locked ? (
        <Pressable
          accessibilityLabel={t('trip.deleteA11y', { day })}
          hitSlop={8}
          onPress={() => onDelete(index)}
          style={{ padding: theme.spacing.xs }}
        >
          <Trash2 color={theme.colors.destructive} size={20} />
        </Pressable>
      ) : null}
      </View>
      {showFooter ? (
        <View
          style={{
            paddingHorizontal: theme.spacing.base,
            paddingBottom: theme.spacing.md,
            gap: theme.spacing.sm,
          }}
        >
          {editingDistance ? (
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: theme.spacing.sm }}>
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
                accessibilityState={{ disabled: busy }}
                disabled={busy}
                onPress={commitDistance}
                hitSlop={6}
                style={{ padding: theme.spacing.sm, opacity: busy ? 0.4 : 1 }}
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
          ) : (
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: theme.spacing.sm }}>
              <EditChip
                icon={<Pencil color={theme.colors.accentBrand} size={14} />}
                label={t('trip.blocks.distanceKm', {
                  distance: Math.round(stage.distance ?? 0),
                })}
                a11yLabel={t('trip.edit.editDistanceA11y', { day })}
                onPress={startEditDistance}
                disabled={busy}
              />
            </View>
          )}
        </View>
      ) : null}
    </View>
  );
}
