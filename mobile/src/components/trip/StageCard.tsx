import { Pressable, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import type { StageData } from '@btp/core';
import { AlertTriangle, CloudSun, Trash2 } from '../ui/icons';
import { useTheme } from '../../theme';
import { formatStageDate } from './roadbook-dates';

// A per-stage identity signature used as the roadbook FlatList key. Stages carry
// no stable id and `dayNumber` is renumbered on every structural edit, so a pure
// index / dayNumber key lets React reuse a row instance for a *different* stage
// after an insert / rest-day / delete shifts positions — the transient diff
// highlight (#1046) would then paint the wrong row. Folding the endpoints in
// makes the key change whenever a row switches to a different underlying stage
// (the placeholder spans a single boundary point, so it never collides with the
// stage it displaces), forcing React to remount that row. dayNumber keeps the
// key unique across the list (#1044 review).
export function stageKey(stage: StageData): string {
  const s = stage.startPoint;
  const e = stage.endPoint;
  return `${stage.dayNumber}|${stage.isRestDay ? 'r' : 's'}|${s.lat},${s.lon}|${e.lat},${e.lon}`;
}

interface StageCardProps {
  stage: StageData;
  index: number;
  // A started trip is read-only (backend 423): the delete action is hidden.
  locked: boolean;
  onDelete: (index: number) => void;
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

// One roadbook row: the stage date (or "Jour N" fallback) + rest tag, start →
// end labels, distance / elevation, a "today" pastille on the current day, a
// weather/alerts column and a delete action. The row is a summary that taps
// through to the stage detail, where per-stage edits (distance) live (#1045);
// only the delete stays on the card. Rendered by RoadbookView; #1039 wires the
// tap-through.
export function StageCard({
  stage,
  index,
  locked,
  onDelete,
  date = null,
  isToday = false,
  onPress,
  highlighted = false,
}: StageCardProps) {
  const { t, i18n } = useTranslation();
  const theme = useTheme();
  const day = stage.dayNumber ?? index + 1;
  const heading = date
    ? formatStageDate(date, i18n.language)
    : t('trip.day', { day: stage.dayNumber ?? '?' });
  // Route title = the stage's places (start → end), per 05-trip-roadbook. The day
  // lives in the left badge (`heading`), so the title never repeats "Jour N": when
  // no label is resolved yet (labels are reverse-geocoded async, ResolveStageLabels)
  // it degrades to a neutral em-dash, not the day.
  const routeLabel =
    stage.startLabel && stage.endLabel
      ? `${stage.startLabel} → ${stage.endLabel}`
      : (stage.startLabel ?? stage.endLabel ?? stage.label ?? '—');

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
    </View>
  );
}
