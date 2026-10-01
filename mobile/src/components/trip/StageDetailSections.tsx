import type { ReactNode } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import type { Difficulty } from '@btp/core';
import { Flag, Gauge, MapPin } from '../ui/icons';
import { useTheme } from '../../theme';

// Themed section cards of the stage detail screen (locations / difficulty /
// surfaces); the stats card lives in StageStatsSection.

// Uppercase muted section label + a bordered card body.
export function Section({ title, children }: { title: string; children: ReactNode }) {
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

export function LocationsSection({ departure, arrival }: { departure: string; arrival: string }) {
  const { t } = useTranslation();
  const theme = useTheme();
  return (
    <Section title={t('trip.stageDetail.sectionLocations')}>
      <View style={{ gap: theme.spacing.sm }}>
        <LocationRow
          icon={<MapPin color={theme.colors.accentBrand} size={16} />}
          label={t('trip.stageDetail.departure')}
          place={departure}
        />
        <LocationRow
          icon={<Flag color={theme.colors.mutedIcon} size={16} />}
          label={t('trip.stageDetail.arrival')}
          place={arrival}
        />
      </View>
    </Section>
  );
}

function LocationRow({ icon, label, place }: { icon: ReactNode; label: string; place: string }) {
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

export function DifficultyPill({ level }: { level: Difficulty }) {
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
export function SurfaceBar({ surfaces }: { surfaces: { surface: string; percent: number }[] }) {
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
          <View key={s.surface} style={{ flex: s.percent, backgroundColor: color(i) }} />
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
