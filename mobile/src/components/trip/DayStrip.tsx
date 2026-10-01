import { Pressable, ScrollView, Text } from 'react-native';
import { useTranslation } from 'react-i18next';
import { stageDate } from '@btp/core';
import { formatStageDate } from './roadbook-dates';
import { useTheme } from '../../theme';

// Horizontal, scrollable day-strip: one chip per stage (its short date, or a
// "Jour N" fallback). The active day is accented, rest days are dashed + muted.
export function DayStrip({
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
        const date = stageDate(startDate, dayNum);
        const label = date ? formatStageDate(date, i18n.language) : t('trip.day', { day: dayNum });
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
