import { type ReactNode } from 'react';
import { Pressable, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { Coffee, Plus } from '../ui/icons';
import { useTheme } from '../../theme';

interface StageInsertRowProps {
  // Insert after this stage index (matches mutations.addStage / insertRestDay).
  afterIndex: number;
  // Day number of the preceding stage, for the a11y labels ("... après le jour N").
  day: number;
  // A manual stage is routed via Valhalla → +étape disabled out of zone.
  outOfZone?: boolean;
  // A mutation for this boundary is in flight: both pills disabled.
  busy?: boolean;
  onAddStage: (afterIndex: number) => void;
  onAddRestDay: (afterIndex: number) => void;
}

// A single accent-bordered pill used in the insertion row.
function InsertPill({
  icon,
  label,
  a11yLabel,
  onPress,
  disabled,
}: {
  icon: ReactNode;
  label: string;
  a11yLabel: string;
  onPress: () => void;
  disabled: boolean;
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
        flex: 1,
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'center',
        gap: theme.spacing.xs,
        borderWidth: 1,
        borderColor: theme.colors.accentBrand,
        backgroundColor: theme.colors.accentSoft,
        borderRadius: theme.radius.full,
        paddingHorizontal: theme.spacing.md,
        paddingVertical: theme.spacing.sm,
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

// The "＋ Étape / ＋ Jour de repos" insertion row rendered between roadbook stages
// (maquette 05 `.insert`). Replaces the per-card add pills so a rider inserts at
// a boundary, not "after a card". Hidden by RoadbookView when the trip is
// read-only (started / ongoing / past).
export function StageInsertRow({
  afterIndex,
  day,
  outOfZone = false,
  busy = false,
  onAddStage,
  onAddRestDay,
}: StageInsertRowProps) {
  const { t } = useTranslation();
  const theme = useTheme();
  return (
    <View
      style={{
        flexDirection: 'row',
        gap: theme.spacing.sm,
        marginHorizontal: theme.spacing.base,
        marginBottom: theme.spacing.md,
      }}
    >
      <InsertPill
        icon={<Plus color={theme.colors.accentBrand} size={14} />}
        label={t('trip.edit.addStage')}
        a11yLabel={t('trip.edit.addStageA11y', { day })}
        onPress={() => onAddStage(afterIndex)}
        disabled={outOfZone || busy}
      />
      <InsertPill
        icon={<Coffee color={theme.colors.accentBrand} size={14} />}
        label={t('trip.edit.addRestDay')}
        a11yLabel={t('trip.edit.addRestDayA11y', { day })}
        onPress={() => onAddRestDay(afterIndex)}
        disabled={busy}
      />
    </View>
  );
}
