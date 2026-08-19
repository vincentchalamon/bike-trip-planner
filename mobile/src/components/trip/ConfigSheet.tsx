import { useEffect, useState } from 'react';
import {
  Alert,
  type AccessibilityActionEvent,
  type GestureResponderEvent,
  type LayoutChangeEvent,
  Pressable,
  ScrollView,
  Text,
  View,
} from 'react-native';
import { useTranslation } from 'react-i18next';
import { Button, DateField, Input, Sheet } from '../ui';
import { Calendar } from '../ui/icons';
import { useTheme } from '../../theme';
import { useTripStore } from '../../store/trip-store';
import { useTripMutations } from '../../hooks/use-trip-mutations';
import type { MutationFailure } from '../../store/gating';
import { FILTERABLE_ACCOMMODATION_TYPES } from '@btp/core/constants';
import {
  PRESETS,
  fromElevationPercent,
  fromFatiguePercent,
  getActivePresetKey,
  toElevationPercent,
  toFatiguePercent,
  type RiderPreset,
} from '@btp/core/pacing-presets';

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

// A themed track slider (no native slider dep): a drag on the track maps the
// touch x to a stepped value. Uses raw responder props (not PanResponder) so the
// commit closure stays inspectable and the gesture is unit-testable via
// onLayout + onResponder* without pulling in PanResponder's touch-history
// internals. `format` renders the accent value; end labels sit under the track.
function Slider({
  label,
  value,
  min,
  max,
  step,
  format,
  minLabel,
  maxLabel,
  disabled,
  onChange,
}: {
  label: string;
  value: number;
  min: number;
  max: number;
  step: number;
  format: (v: number) => string;
  minLabel: string;
  maxLabel: string;
  disabled: boolean;
  onChange: (v: number) => void;
}) {
  const theme = useTheme();
  const [width, setWidth] = useState(0);
  const pct = max > min ? clamp((value - min) / (max - min), 0, 1) : 0;
  const THUMB = 22;

  const commit = (e: GestureResponderEvent) => {
    if (disabled || width <= 0) return;
    const ratio = clamp(e.nativeEvent.locationX / width, 0, 1);
    const stepped = Math.round((min + ratio * (max - min)) / step) * step;
    onChange(clamp(stepped, min, max));
  };

  return (
    <View style={{ paddingVertical: theme.spacing.sm }}>
      <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'baseline' }}>
        <Text
          style={{
            color: theme.colors.foreground,
            fontFamily: theme.fonts.sansMedium,
            fontSize: 14,
          }}
        >
          {label}
        </Text>
        <Text
          style={{
            color: theme.colors.accentBrand,
            fontFamily: theme.fonts.mono,
            fontSize: 14,
          }}
        >
          {format(value)}
        </Text>
      </View>
      <View
        accessibilityRole="adjustable"
        accessibilityLabel={label}
        accessibilityValue={{ min, max, now: value, text: format(value) }}
        // Screen readers (VoiceOver/TalkBack) drive an "adjustable" via
        // increment/decrement actions, not raw touch — without these the sliders
        // are unusable with a screen reader (the old +/- Stepper was operable).
        accessibilityActions={[{ name: 'increment' }, { name: 'decrement' }]}
        onAccessibilityAction={(e: AccessibilityActionEvent) => {
          if (disabled) return;
          if (e.nativeEvent.actionName === 'increment') onChange(clamp(value + step, min, max));
          if (e.nativeEvent.actionName === 'decrement') onChange(clamp(value - step, min, max));
        }}
        onLayout={(e: LayoutChangeEvent) => setWidth(e.nativeEvent.layout.width)}
        onStartShouldSetResponder={() => !disabled}
        onMoveShouldSetResponder={() => !disabled}
        onResponderGrant={commit}
        onResponderMove={commit}
        hitSlop={{ top: 12, bottom: 12 }}
        style={{
          height: THUMB + 8,
          justifyContent: 'center',
          marginTop: theme.spacing.sm,
          opacity: disabled ? 0.5 : 1,
        }}
      >
        <View style={{ height: 6, borderRadius: 3, backgroundColor: theme.colors.muted }}>
          <View
            style={{
              position: 'absolute',
              left: 0,
              top: 0,
              bottom: 0,
              width: `${pct * 100}%`,
              borderRadius: 3,
              backgroundColor: theme.colors.brandFill,
            }}
          />
          <View
            pointerEvents="none"
            style={{
              position: 'absolute',
              top: 3 - THUMB / 2,
              left: `${pct * 100}%`,
              marginLeft: -THUMB / 2,
              width: THUMB,
              height: THUMB,
              borderRadius: THUMB / 2,
              backgroundColor: theme.colors.brandFill,
              borderWidth: 3,
              borderColor: theme.colors.card,
              ...theme.shadows.soft,
            }}
          />
        </View>
      </View>
      <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginTop: theme.spacing.xs }}>
        <Text style={{ color: theme.colors.mutedForeground, fontFamily: theme.fonts.mono, fontSize: 11 }}>
          {minLabel}
        </Text>
        <Text style={{ color: theme.colors.mutedForeground, fontFamily: theme.fonts.mono, fontSize: 11 }}>
          {maxLabel}
        </Text>
      </View>
    </View>
  );
}

// A themed track switch (no native Switch dep): a rounded track whose knob
// slides to the checked end; the track fills with the brand accent when on
// (the theme has no dedicated success/green token — brand accent is the closest
// themed match for the mockup's green "on" state).
function Switch({
  value,
  disabled,
  onValueChange,
  label,
}: {
  value: boolean;
  disabled: boolean;
  onValueChange: (v: boolean) => void;
  label: string;
}) {
  const theme = useTheme();
  const W = 46;
  const H = 28;
  const PAD = 3;
  const KNOB = H - 2 * PAD;
  return (
    <Pressable
      accessibilityRole="switch"
      accessibilityLabel={label}
      accessibilityState={{ checked: value, disabled }}
      disabled={disabled}
      onPress={() => onValueChange(!value)}
      hitSlop={8}
      style={{
        width: W,
        height: H,
        borderRadius: H / 2,
        padding: PAD,
        justifyContent: 'center',
        backgroundColor: value ? theme.colors.brandFill : theme.colors.muted,
        borderWidth: value ? 0 : 1,
        borderColor: theme.colors.border,
        opacity: disabled ? 0.5 : 1,
      }}
    >
      <View
        style={{
          width: KNOB,
          height: KNOB,
          borderRadius: KNOB / 2,
          backgroundColor: '#ffffff',
          alignSelf: value ? 'flex-end' : 'flex-start',
          ...theme.shadows.soft,
        }}
      />
    </Pressable>
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
  function confirmRecompute(run: () => Promise<boolean>) {
    Alert.alert(t('config.confirmTitle'), t('config.confirmMessage'), [
      { text: t('config.cancel'), style: 'cancel' },
      {
        text: t('config.confirm'),
        style: 'destructive',
        onPress: () => {
          // Arm the baseline, but disarm it if the commit fails (e.g. a
          // malformed free-text date → 422): otherwise no trip_ready follows and
          // the stale baseline would light spurious highlights on the NEXT
          // unrelated successful recompute.
          useTripStore.getState().armConfigDiff();
          void run().then((ok) => {
            // Token-aware disarm: a failed commit consumes its own generation
            // without nulling the shared baseline under a second still-pending one.
            if (!ok) useTripStore.getState().disarmConfigDiff();
          });
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
          <Slider
            label={t('config.maxDistance')}
            value={pacing.maxDistancePerDay}
            min={30}
            max={300}
            step={5}
            disabled={isLocked}
            format={(v) => t('config.valueKm', { value: v })}
            minLabel={t('config.valueKm', { value: 30 })}
            maxLabel={t('config.valueKm', { value: 300 })}
            onChange={(v) => setPacing((p) => ({ ...p, maxDistancePerDay: v }))}
          />
          <Slider
            label={t('config.averageSpeed')}
            value={pacing.averageSpeed}
            min={5}
            max={50}
            step={1}
            disabled={isLocked}
            format={(v) => t('config.valueKmh', { value: v })}
            minLabel={t('config.valueKmh', { value: 5 })}
            maxLabel={t('config.valueKmh', { value: 50 })}
            onChange={(v) => setPacing((p) => ({ ...p, averageSpeed: v }))}
          />
          <Slider
            label={t('config.departureHour')}
            value={pacing.departureHour}
            min={0}
            max={23}
            step={1}
            disabled={isLocked}
            format={(v) => t('config.valueHour', { value: String(v).padStart(2, '0') })}
            minLabel={t('config.valueHour', { value: '00' })}
            maxLabel={t('config.valueHour', { value: '23' })}
            onChange={(v) => setPacing((p) => ({ ...p, departureHour: v }))}
          />
          <Slider
            label={t('config.fatigue')}
            value={toFatiguePercent(pacing.fatigueFactor)}
            min={1}
            max={50}
            step={1}
            disabled={isLocked}
            format={(v) => t('config.valuePercent', { value: v })}
            minLabel={t('config.valuePercent', { value: 1 })}
            maxLabel={t('config.valuePercent', { value: 50 })}
            onChange={(v) => setPacing((p) => ({ ...p, fatigueFactor: fromFatiguePercent(v) }))}
          />
          <Slider
            label={t('config.elevation')}
            value={toElevationPercent(pacing.elevationPenalty)}
            min={1}
            max={100}
            step={1}
            disabled={isLocked}
            format={(v) => t('config.valuePercent', { value: v })}
            minLabel={t('config.elevationLow')}
            maxLabel={t('config.elevationHigh')}
            onChange={(v) =>
              setPacing((p) => ({ ...p, elevationPenalty: fromElevationPercent(v) }))
            }
          />
          <View
            style={{
              flexDirection: 'row',
              alignItems: 'center',
              justifyContent: 'space-between',
              marginTop: theme.spacing.sm,
              paddingVertical: theme.spacing.md,
              paddingHorizontal: theme.spacing.md,
              borderRadius: theme.radius.lg,
              borderWidth: 1,
              borderColor: theme.colors.border,
              backgroundColor: theme.colors.secondary,
            }}
          >
            <Text
              style={{
                color: theme.colors.foreground,
                fontFamily: theme.fonts.sansMedium,
                fontSize: 14,
              }}
            >
              {t('config.ebikeMode')}
            </Text>
            <Switch
              label={t('config.ebikeMode')}
              value={pacing.ebikeMode}
              disabled={isLocked}
              onValueChange={(v) => setPacing((p) => ({ ...p, ebikeMode: v }))}
            />
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
        <View style={{ gap: theme.spacing.sm }}>
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
                  paddingVertical: theme.spacing.md,
                  paddingHorizontal: theme.spacing.md,
                  borderRadius: theme.radius.lg,
                  borderWidth: 1,
                  borderColor: theme.colors.border,
                  backgroundColor: theme.colors.secondary,
                }}
              >
                <Text
                  style={{
                    color: theme.colors.foreground,
                    fontFamily: theme.fonts.sansMedium,
                    fontSize: 14,
                  }}
                >
                  {t(`config.type_${type}` as const)}
                </Text>
                <Switch
                  label={t(`config.type_${type}` as const)}
                  value={enabled}
                  disabled={isLocked || isLast}
                  onValueChange={() => toggleAccommodationType(type)}
                />
              </View>
            );
          })}
        </View>

        <View style={{ height: theme.spacing.lg }} />

        {/* Dates */}
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: theme.spacing.xs, marginBottom: theme.spacing.sm }}>
          <Calendar size={16} color={theme.colors.accentBrand} />
          <View style={{ flex: 1 }}>
            <SectionTitle
              title={t('config.datesTitle')}
              description={t('config.datesDescription')}
            />
          </View>
        </View>
        <View style={{ flexDirection: 'row', gap: theme.spacing.sm }}>
          <View style={{ flex: 1 }}>
            <DateField
              label={t('config.startDate')}
              value={startDraft}
              onChange={setStartDraft}
              placeholder={t('config.datePlaceholder')}
              accessibilityLabel={t('config.startDate')}
              clearLabel={t('trips.clearDate')}
              disabled={isLocked}
            />
          </View>
          <View style={{ flex: 1 }}>
            <DateField
              label={t('config.endDate')}
              value={endDraft}
              onChange={setEndDraft}
              placeholder={t('config.datePlaceholder')}
              accessibilityLabel={t('config.endDate')}
              clearLabel={t('trips.clearDate')}
              disabled={isLocked}
            />
          </View>
        </View>
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
