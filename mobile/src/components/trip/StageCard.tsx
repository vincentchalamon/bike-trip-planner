import { Pressable, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import type { StageData } from '@btp/core';
import { Trash2 } from '../ui/icons';
import { useTheme } from '../../theme';
import { formatStageDate } from './roadbook-dates';
import { StageDataBlocks } from './StageDataBlocks';

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
  // Routes a `navigate` alert action to the map segment (#1040). Forwarded to
  // the per-day data blocks.
  onAlertNavigate?: (segments: [number, number][][]) => void;
  // Tap-through to the full-screen stage detail (#1039). Wired on the stage
  // summary only (not the data blocks below, so their own controls keep working).
  onPress?: (index: number) => void;
}

// One roadbook row: the stage date (or "Jour N" fallback) + rest tag, start →
// end labels, distance / elevation, a "today" pastille on the current day, and
// a delete action when the trip is still editable. Rendered by RoadbookView;
// #1039 wires the tap-through to the stage detail.
export function StageCard({
  stage,
  index,
  locked,
  onDelete,
  date = null,
  isToday = false,
  onAlertNavigate,
  onPress,
}: StageCardProps) {
  const { t, i18n } = useTranslation();
  const theme = useTheme();
  const heading = date
    ? formatStageDate(date, i18n.language)
    : t('trip.day', { day: stage.dayNumber ?? '?' });
  return (
    <View style={{ borderBottomWidth: 1, borderBottomColor: theme.colors.border }}>
      <View
        style={{
          flexDirection: 'row',
          alignItems: 'center',
          paddingVertical: theme.spacing.md,
          paddingHorizontal: theme.spacing.base,
        }}
      >
      <Pressable
        disabled={!onPress}
        onPress={onPress ? () => onPress(index) : undefined}
        accessibilityRole={onPress ? 'button' : undefined}
        accessibilityLabel={
          onPress ? t('trip.openStageA11y', { day: stage.dayNumber ?? index + 1 }) : undefined
        }
        style={{ flex: 1 }}
      >
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: theme.spacing.sm }}>
          <Text
            style={{
              color: theme.colors.foreground,
              fontFamily: theme.fonts.sansSemibold,
              fontSize: 16,
            }}
          >
            {heading}
            {stage.isRestDay ? ` · ${t('trip.rest')}` : ''}
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
            fontFamily: theme.fonts.sans,
            fontSize: 14,
            marginTop: 2,
          }}
        >
          {stage.startLabel ?? '?'} → {stage.endLabel ?? stage.label ?? '?'}
        </Text>
        <Text
          style={{
            color: theme.colors.mutedForeground,
            fontFamily: theme.fonts.mono,
            fontSize: 13,
            marginTop: 2,
          }}
        >
          {t('trip.stageMeta', {
            distance: Math.round(stage.distance ?? 0),
            elevation: Math.round(stage.elevation ?? 0),
          })}
        </Text>
      </Pressable>
      {!locked ? (
        <Pressable
          accessibilityLabel={t('trip.deleteA11y', { day: stage.dayNumber ?? index + 1 })}
          hitSlop={8}
          onPress={() => onDelete(index)}
          style={{ padding: theme.spacing.sm }}
        >
          <Trash2 color={theme.colors.destructive} size={20} />
        </Pressable>
      ) : null}
      </View>
      <View
        style={{
          paddingHorizontal: theme.spacing.base,
          paddingBottom: theme.spacing.md,
        }}
      >
        <StageDataBlocks stage={stage} onAlertNavigate={onAlertNavigate} />
      </View>
    </View>
  );
}
