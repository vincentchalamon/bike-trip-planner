import { useEffect, useState } from 'react';
import { Alert, Pressable, ScrollView, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { Button, Input, Sheet } from '../ui';
import { useTheme } from '../../theme';
import { useTripStore } from '../../store/trip-store';
import { useTripMutations } from '../../hooks/use-trip-mutations';
import type { MutationFailure } from '../../store/gating';
import {
  FILTERABLE_ACCOMMODATION_TYPES,
  PRESETS,
  fromElevationPercent,
  fromFatiguePercent,
  getActivePresetKey,
  toElevationPercent,
  toFatiguePercent,
  type RiderPreset,
} from '../../lib/pacing-presets';

interface ConfigSheetProps {
  tripId: string;
  visible: boolean;
  onClose: () => void;
}

// The editable pacing slice held as a local draft while the sheet is open, so a
// tap live-previews the value before the destructive recompute is committed.
interface PacingDraft {
  fatigueFactor: number;
  elevationPenalty: number;
  maxDistancePerDay: number;
  averageSpeed: number;
  ebikeMode: boolean;
  departureHour: number;
}

function clamp(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value));
}

// A compact -/+ numeric control (no slider dep on RN). `format` renders the
// current value; `label` is used for the accessible +/- labels.
function Stepper({
  label,
  value,
  min,
  max,
  step,
  format,
  onChange,
}: {
  label: string;
  value: number;
  min: number;
  max: number;
  step: number;
  format: (v: number) => string;
  onChange: (v: number) => void;
}) {
  const theme = useTheme();
  const { t } = useTranslation();
  const btn = (delta: number, a11y: string) => (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={a11y}
      disabled={delta < 0 ? value <= min : value >= max}
      onPress={() => onChange(clamp(value + delta, min, max))}
      hitSlop={6}
      style={{
        width: 32,
        height: 32,
        borderRadius: theme.radius.md,
        borderWidth: 1,
        borderColor: theme.colors.border,
        alignItems: 'center',
        justifyContent: 'center',
        opacity: (delta < 0 ? value <= min : value >= max) ? 0.4 : 1,
      }}
    >
      <Text style={{ color: theme.colors.foreground, fontSize: 18 }}>
        {delta < 0 ? '−' : '+'}
      </Text>
    </Pressable>
  );
  return (
    <View
      style={{
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
        paddingVertical: theme.spacing.xs,
        gap: theme.spacing.sm,
      }}
    >
      <Text style={{ color: theme.colors.foreground, fontSize: 14, flex: 1 }}>
        {label}
      </Text>
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: theme.spacing.sm }}>
        {btn(-step, t('config.decrease', { label }))}
        <Text
          style={{
            color: theme.colors.mutedForeground,
            fontFamily: theme.fonts.mono,
            fontSize: 14,
            minWidth: 64,
            textAlign: 'center',
          }}
        >
          {format(value)}
        </Text>
        {btn(step, t('config.increase', { label }))}
      </View>
    </View>
  );
}

function SectionTitle({ title, description }: { title: string; description?: string }) {
  const theme = useTheme();
  return (
    <View style={{ marginBottom: theme.spacing.sm }}>
      <Text
        style={{
          color: theme.colors.foreground,
          fontFamily: theme.fonts.sansSemibold,
          fontSize: 15,
        }}
      >
        {title}
      </Text>
      {description ? (
        <Text
          style={{
            color: theme.colors.mutedForeground,
            fontFamily: theme.fonts.sans,
            fontSize: 12,
            marginTop: 2,
          }}
        >
          {description}
        </Text>
      ) : null}
    </View>
  );
}

// Trip config panel (#1046): title + full pacing (with live-preview + presets)
// + accommodation types + dates. Title and accommodation types apply on the
// spot (they don't re-split the trip). Pacing and dates are destructive — they
// regenerate the stage découpage — so committing them goes through a
// confirmation and arms the post-recompute diff-highlight.
export function ConfigSheet({ tripId, visible, onClose }: ConfigSheetProps) {
  const { t } = useTranslation();
  const theme = useTheme();

  const storeTitle = useTripStore((s) => s.title);
  const isLocked = useTripStore((s) => s.isLocked);
  const startDate = useTripStore((s) => s.startDate);
  const endDate = useTripStore((s) => s.endDate);
  const enabledAccommodationTypes = useTripStore((s) => s.enabledAccommodationTypes);
  const fatigueFactor = useTripStore((s) => s.fatigueFactor);
  const elevationPenalty = useTripStore((s) => s.elevationPenalty);
  const maxDistancePerDay = useTripStore((s) => s.maxDistancePerDay);
  const averageSpeed = useTripStore((s) => s.averageSpeed);
  const ebikeMode = useTripStore((s) => s.ebikeMode);
  const departureHour = useTripStore((s) => s.departureHour);

  const onFailure = (_reason: MutationFailure) =>
    Alert.alert(t('common.error'), t('config.failed'));
  const mutations = useTripMutations(tripId, onFailure);

  const [titleDraft, setTitleDraft] = useState('');
  const [startDraft, setStartDraft] = useState('');
  const [endDraft, setEndDraft] = useState('');
  const [pacing, setPacing] = useState<PacingDraft>({
    fatigueFactor,
    elevationPenalty,
    maxDistancePerDay,
    averageSpeed,
    ebikeMode,
    departureHour,
  });

  // Re-seed the drafts from the store each time the sheet opens so a cancelled
  // edit never leaks into the next open (the store holds the committed truth).
  useEffect(() => {
    if (!visible) return;
    setTitleDraft(storeTitle ?? '');
    setStartDraft(startDate ?? '');
    setEndDraft(endDate ?? '');
    setPacing({
      fatigueFactor,
      elevationPenalty,
      maxDistancePerDay,
      averageSpeed,
      ebikeMode,
      departureHour,
    });
  }, [visible]); // eslint-disable-line react-hooks/exhaustive-deps

  const activePreset = getActivePresetKey(
    pacing.maxDistancePerDay,
    pacing.averageSpeed,
    pacing.elevationPenalty,
    pacing.fatigueFactor,
  );

  const pacingChanged =
    pacing.fatigueFactor !== fatigueFactor ||
    pacing.elevationPenalty !== elevationPenalty ||
    pacing.maxDistancePerDay !== maxDistancePerDay ||
    pacing.averageSpeed !== averageSpeed ||
    pacing.ebikeMode !== ebikeMode ||
    pacing.departureHour !== departureHour;

  const datesChanged =
    (startDraft || null) !== (startDate ?? null) ||
    (endDraft || null) !== (endDate ?? null);

  function applyPreset(preset: RiderPreset) {
    setPacing((p) => ({
      ...p,
      maxDistancePerDay: preset.maxDistancePerDay,
      averageSpeed: preset.averageSpeed,
      elevationPenalty: fromElevationPercent(preset.elevationPenaltyPercent),
      fatigueFactor: fromFatiguePercent(preset.fatiguePercent),
    }));
  }

  function saveTitle() {
    const next = titleDraft.trim();
    if (!next || next === (storeTitle ?? '')) return;
    void mutations.updateTitle(next);
  }

  function toggleAccommodationType(type: string) {
    const enabled = enabledAccommodationTypes.includes(type);
    // Keep at least one type enabled (backend rejects an empty set).
    if (enabled && enabledAccommodationTypes.length <= 1) return;
    const next = enabled
      ? enabledAccommodationTypes.filter((x) => x !== type)
      : [...enabledAccommodationTypes, type];
    void mutations.updateAccommodationTypes(next);
  }

  // A destructive commit re-splits the trip: confirm, then arm the diff so the
  // roadbook highlights the stages that moved once the recompute streams back.
  function confirmRecompute(run: () => void) {
    Alert.alert(t('config.confirmTitle'), t('config.confirmMessage'), [
      { text: t('config.cancel'), style: 'cancel' },
      {
        text: t('config.confirm'),
        style: 'destructive',
        onPress: () => {
          useTripStore.getState().armConfigDiff();
          run();
          onClose();
        },
      },
    ]);
  }

  function applyPacing() {
    confirmRecompute(() =>
      mutations.updatePacing({
        fatigueFactor: pacing.fatigueFactor,
        elevationPenalty: pacing.elevationPenalty,
        maxDistancePerDay: pacing.maxDistancePerDay,
        averageSpeed: pacing.averageSpeed,
        ebikeMode: pacing.ebikeMode,
        departureHour: pacing.departureHour,
      }),
    );
  }

  function applyDates() {
    confirmRecompute(() =>
      mutations.updateDates(startDraft || null, endDraft || null),
    );
  }

  return (
    <Sheet visible={visible} onClose={onClose} title={t('config.title')}>
      <ScrollView style={{ maxHeight: 460 }} keyboardShouldPersistTaps="handled">
        {/* Title */}
        <SectionTitle title={t('config.titleSection')} />
        <Input
          value={titleDraft}
          onChangeText={setTitleDraft}
          placeholder={t('config.titlePlaceholder')}
          editable={!isLocked}
          accessibilityLabel={t('config.titleSection')}
        />
        <View style={{ marginTop: theme.spacing.sm }}>
          <Button
            label={t('config.save')}
            variant="secondary"
            size="sm"
            disabled={isLocked || !titleDraft.trim() || titleDraft.trim() === (storeTitle ?? '')}
            onPress={saveTitle}
          />
        </View>

        <View style={{ height: theme.spacing.lg }} />

        {/* Pacing */}
        <SectionTitle
          title={t('config.pacingTitle')}
          description={t('config.pacingDescription')}
        />
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: theme.spacing.sm }}>
          {PRESETS.map((preset) => (
            <Pressable
              key={preset.key}
              accessibilityRole="button"
              accessibilityState={{ selected: activePreset === preset.key }}
              disabled={isLocked}
              onPress={() => applyPreset(preset)}
              style={{
                paddingHorizontal: theme.spacing.md,
                paddingVertical: theme.spacing.xs,
                borderRadius: theme.radius.full,
                borderWidth: 1,
                borderColor:
                  activePreset === preset.key ? theme.colors.brandFill : theme.colors.border,
                backgroundColor:
                  activePreset === preset.key ? theme.colors.accentSoft : 'transparent',
              }}
            >
              <Text style={{ color: theme.colors.foreground, fontSize: 13 }}>
                {t(`config.preset_${preset.key}` as const)}
              </Text>
            </Pressable>
          ))}
        </View>

        <View style={{ marginTop: theme.spacing.sm }}>
          <Stepper
            label={t('config.maxDistance')}
            value={pacing.maxDistancePerDay}
            min={30}
            max={300}
            step={5}
            format={(v) => t('config.valueKm', { value: v })}
            onChange={(v) => setPacing((p) => ({ ...p, maxDistancePerDay: v }))}
          />
          <Stepper
            label={t('config.averageSpeed')}
            value={pacing.averageSpeed}
            min={5}
            max={50}
            step={1}
            format={(v) => t('config.valueKmh', { value: v })}
            onChange={(v) => setPacing((p) => ({ ...p, averageSpeed: v }))}
          />
          <Stepper
            label={t('config.departureHour')}
            value={pacing.departureHour}
            min={0}
            max={23}
            step={1}
            format={(v) => t('config.valueHour', { value: String(v).padStart(2, '0') })}
            onChange={(v) => setPacing((p) => ({ ...p, departureHour: v }))}
          />
          <Stepper
            label={t('config.fatigue')}
            value={toFatiguePercent(pacing.fatigueFactor)}
            min={1}
            max={50}
            step={1}
            format={(v) => t('config.valuePercent', { value: v })}
            onChange={(v) => setPacing((p) => ({ ...p, fatigueFactor: fromFatiguePercent(v) }))}
          />
          <Stepper
            label={t('config.elevation')}
            value={toElevationPercent(pacing.elevationPenalty)}
            min={1}
            max={100}
            step={1}
            format={(v) => t('config.valuePercent', { value: v })}
            onChange={(v) =>
              setPacing((p) => ({ ...p, elevationPenalty: fromElevationPercent(v) }))
            }
          />
          <View
            style={{
              flexDirection: 'row',
              alignItems: 'center',
              justifyContent: 'space-between',
              paddingVertical: theme.spacing.xs,
            }}
          >
            <Text style={{ color: theme.colors.foreground, fontSize: 14 }}>
              {t('config.ebikeMode')}
            </Text>
            <Pressable
              accessibilityRole="switch"
              accessibilityLabel={t('config.ebikeMode')}
              accessibilityState={{ checked: pacing.ebikeMode }}
              disabled={isLocked}
              onPress={() => setPacing((p) => ({ ...p, ebikeMode: !p.ebikeMode }))}
              style={{
                paddingHorizontal: theme.spacing.md,
                paddingVertical: theme.spacing.xs,
                borderRadius: theme.radius.full,
                borderWidth: 1,
                borderColor: pacing.ebikeMode ? theme.colors.brandFill : theme.colors.border,
                backgroundColor: pacing.ebikeMode ? theme.colors.accentSoft : 'transparent',
              }}
            >
              <Text style={{ color: theme.colors.foreground, fontSize: 13 }}>
                {pacing.ebikeMode ? t('config.on') : t('config.off')}
              </Text>
            </Pressable>
          </View>
        </View>
        <View style={{ marginTop: theme.spacing.sm }}>
          <Button
            label={t('config.recompute')}
            variant="primary"
            size="sm"
            disabled={isLocked || !pacingChanged}
            onPress={applyPacing}
          />
        </View>

        <View style={{ height: theme.spacing.lg }} />

        {/* Accommodation types */}
        <SectionTitle
          title={t('config.accommodationTitle')}
          description={t('config.accommodationDescription')}
        />
        {FILTERABLE_ACCOMMODATION_TYPES.map((type) => {
          const enabled = enabledAccommodationTypes.includes(type);
          const isLast = enabled && enabledAccommodationTypes.length <= 1;
          return (
            <View
              key={type}
              style={{
                flexDirection: 'row',
                alignItems: 'center',
                justifyContent: 'space-between',
                paddingVertical: theme.spacing.xs,
              }}
            >
              <Text style={{ color: theme.colors.foreground, fontSize: 14 }}>
                {t(`config.type_${type}` as const)}
              </Text>
              <Pressable
                accessibilityRole="switch"
                accessibilityLabel={t(`config.type_${type}` as const)}
                accessibilityState={{ checked: enabled, disabled: isLocked || isLast }}
                disabled={isLocked || isLast}
                onPress={() => toggleAccommodationType(type)}
                style={{
                  paddingHorizontal: theme.spacing.md,
                  paddingVertical: theme.spacing.xs,
                  borderRadius: theme.radius.full,
                  borderWidth: 1,
                  borderColor: enabled ? theme.colors.brandFill : theme.colors.border,
                  backgroundColor: enabled ? theme.colors.accentSoft : 'transparent',
                  opacity: isLast ? 0.5 : 1,
                }}
              >
                <Text style={{ color: theme.colors.foreground, fontSize: 13 }}>
                  {enabled ? t('config.on') : t('config.off')}
                </Text>
              </Pressable>
            </View>
          );
        })}

        <View style={{ height: theme.spacing.lg }} />

        {/* Dates */}
        <SectionTitle
          title={t('config.datesTitle')}
          description={t('config.datesDescription')}
        />
        <Input
          label={t('config.startDate')}
          value={startDraft}
          onChangeText={setStartDraft}
          placeholder="AAAA-MM-JJ"
          editable={!isLocked}
          autoCapitalize="none"
        />
        <View style={{ height: theme.spacing.sm }} />
        <Input
          label={t('config.endDate')}
          value={endDraft}
          onChangeText={setEndDraft}
          placeholder="AAAA-MM-JJ"
          editable={!isLocked}
          autoCapitalize="none"
        />
        <View style={{ marginTop: theme.spacing.sm }}>
          <Button
            label={t('config.recompute')}
            variant="primary"
            size="sm"
            disabled={isLocked || !datesChanged}
            onPress={applyDates}
          />
        </View>
      </ScrollView>
    </Sheet>
  );
}
