import { type ReactNode, useState } from 'react';
import { Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { Check, Mountain, Pencil, Route, X } from '../ui/icons';
import { Section } from './StageDetailSections';
import type { StageStats } from './stage-detail';
import { useTheme } from '../../theme';

// Distance / ascent / descent card with the inline distance editor. The editor
// state is this stage's own: the parent keys this section on the stage index so
// paging to another stage drops an open editor.
export function StageStatsSection({
  stats,
  day,
  editable,
  onCommitDistance,
}: {
  stats: StageStats;
  day: number;
  editable: boolean;
  onCommitDistance: (km: number) => void;
}) {
  const { t } = useTranslation();
  const theme = useTheme();
  const [editingDistance, setEditingDistance] = useState(false);
  const [draft, setDraft] = useState('');

  function startEditDistance(): void {
    setDraft(String(stats.distanceKm));
    setEditingDistance(true);
  }

  function commitDistance(): void {
    const km = Number(draft.replace(',', '.'));
    if (!Number.isFinite(km) || km <= 0) return;
    setEditingDistance(false);
    onCommitDistance(km);
  }

  return (
    <Section title={t('trip.stageDetail.sectionStats')}>
      <View style={styles.statsRow}>
        <DistanceStat
          value={t('trip.stageDetail.distanceValue', {
            value: stats.distanceKm,
          })}
          editable={editable}
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
  );
}

function StatCell({ icon, label, value }: { icon: ReactNode; label: string; value: string }) {
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

const styles = StyleSheet.create({
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
