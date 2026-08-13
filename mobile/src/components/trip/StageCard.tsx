import { Pressable, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import type { StageData } from '@btp/core';
import { Trash2 } from '../ui/icons';
import { useTheme } from '../../theme';

interface StageCardProps {
  stage: StageData;
  index: number;
  // A started trip is read-only (backend 423): the delete action is hidden.
  locked: boolean;
  onDelete: (index: number) => void;
}

// One roadbook row: day number (+ rest tag), start → end labels, distance /
// elevation, and a delete action when the trip is still editable. Rendered by
// RoadbookView; #1039 wires the tap-through to the stage detail.
export function StageCard({ stage, index, locked, onDelete }: StageCardProps) {
  const { t } = useTranslation();
  const theme = useTheme();
  return (
    <View
      style={{
        flexDirection: 'row',
        alignItems: 'center',
        paddingVertical: theme.spacing.md,
        paddingHorizontal: theme.spacing.base,
        borderBottomWidth: 1,
        borderBottomColor: theme.colors.border,
      }}
    >
      <View style={{ flex: 1 }}>
        <Text
          style={{
            color: theme.colors.foreground,
            fontFamily: theme.fonts.sansSemibold,
            fontSize: 16,
          }}
        >
          {t('trip.day', { day: stage.dayNumber ?? '?' })}
          {stage.isRestDay ? ` · ${t('trip.rest')}` : ''}
        </Text>
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
      </View>
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
  );
}
