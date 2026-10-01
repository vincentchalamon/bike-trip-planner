import type { ReactNode } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { ArrowLeft, ChevronRight } from '../ui/icons';
import { ExportButton } from './ExportButton';
import { hasNextStage, hasPrevStage } from './stage-detail';
import { useTheme } from '../../theme';

// Bounded prev/next topbar of the stage detail screen, with the centered
// date/position title and the per-stage export.
export function StageDetailTopbar({
  heading,
  isRestDay,
  index,
  count,
  onNavigate,
  tripId,
  tripTitle,
  stageId,
  day,
}: {
  heading: string;
  isRestDay: boolean;
  index: number;
  count: number;
  onNavigate: (index: number) => void;
  tripId: string | null;
  tripTitle: string;
  stageId: string;
  day: number;
}) {
  const { t } = useTranslation();
  const theme = useTheme();
  return (
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
        disabled={!hasPrevStage(index)}
        onPress={() => onNavigate(index - 1)}
      >
        <ArrowLeft
          color={hasPrevStage(index) ? theme.colors.foreground : theme.colors.mutedIcon}
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
          {isRestDay ? ` · ${t('trip.rest')}` : ''}
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
            current: index + 1,
            total: count,
          })}
        </Text>
      </View>
      <IconButton
        a11yLabel={t('trip.stageDetail.next')}
        disabled={!hasNextStage(index, count)}
        onPress={() => onNavigate(index + 1)}
      >
        <ChevronRight
          color={hasNextStage(index, count) ? theme.colors.foreground : theme.colors.mutedIcon}
          size={22}
        />
      </IconButton>
      {tripId ? (
        <ExportButton
          tripId={tripId}
          tripTitle={tripTitle}
          stage={{ id: stageId, dayNumber: day }}
        />
      ) : null}
    </View>
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

const styles = StyleSheet.create({
  topbar: {
    flexDirection: 'row',
    alignItems: 'center',
    borderBottomWidth: StyleSheet.hairlineWidth,
  },
  titleWrap: { flex: 1, alignItems: 'center' },
});
