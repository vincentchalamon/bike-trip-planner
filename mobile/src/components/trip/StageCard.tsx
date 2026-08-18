import { type ReactNode, useEffect, useState } from 'react';
import { Pressable, Text, TextInput, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import type { StageData } from '@btp/core';
import { Check, Coffee, Pencil, Plus, Trash2, X } from '../ui/icons';
import { useTheme } from '../../theme';
import { formatStageDate } from './roadbook-dates';
import { StageDataBlocks } from './StageDataBlocks';

// A per-stage identity signature used both as the roadbook FlatList key and as
// the reset trigger for a StageCard's local edit state. Stages carry no stable
// id and `dayNumber` is renumbered on every structural edit, so a pure index /
// dayNumber key lets React reuse a row instance for a *different* stage after an
// insert / rest-day / delete shifts positions — a stale open distance editor
// would then commit onto the wrong stage. Folding the endpoints in makes the key
// change whenever a row switches to a different underlying stage (the placeholder
// spans a single boundary point, so it never collides with the stage it
// displaces), forcing React to remount that row and tear down the stale editor.
// dayNumber keeps the key unique across the list (#1044 review).
export function stageKey(stage: StageData): string {
  const s = stage.startPoint;
  const e = stage.endPoint;
  return `${stage.dayNumber}|${stage.isRestDay ? 'r' : 's'}|${s.lat},${s.lon}|${e.lat},${e.lon}`;
}

interface StageCardProps {
  stage: StageData;
  index: number;
  // A started trip is read-only (backend 423): every edit action is hidden.
  locked: boolean;
  // Route outside the covered area: rerouting edits (+stage / distance) are
  // hidden; a rest day and a delete (no Valhalla reroute) stay available.
  outOfZone?: boolean;
  onDelete: (index: number) => void;
  // Insert a manual stage / rest day after this row (routing vs non-routing).
  onAddStage?: (index: number) => void;
  onAddRestDay?: (index: number) => void;
  // Commit an edited distance (km) for this stage; the backend re-splits and
  // streams the authoritative stages over SSE.
  onEditDistance?: (index: number, distanceKm: number) => void;
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

// A compact pill action used by the inline edit footer.
function EditChip({
  icon,
  label,
  onPress,
  a11yLabel,
  disabled = false,
}: {
  icon: ReactNode;
  label: string;
  onPress: () => void;
  a11yLabel: string;
  disabled?: boolean;
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
        flexDirection: 'row',
        alignItems: 'center',
        gap: theme.spacing.xs,
        borderWidth: 1,
        borderColor: theme.colors.border,
        borderRadius: theme.radius.full,
        paddingHorizontal: theme.spacing.md,
        paddingVertical: theme.spacing.xs,
        opacity: disabled ? 0.4 : 1,
      }}
    >
      {icon}
      <Text
        style={{
          color: theme.colors.foreground,
          fontFamily: theme.fonts.sansMedium,
          fontSize: 13,
        }}
      >
        {label}
      </Text>
    </Pressable>
  );
}

// One roadbook row: the stage date (or "Jour N" fallback) + rest tag, start →
// end labels, distance / elevation, a "today" pastille on the current day, and
// an inline edit footer (＋étape / ＋repos / distance / delete) when the trip is
// still editable (#1044). Rendered by RoadbookView; #1039 wires the tap-through.
export function StageCard({
  stage,
  index,
  locked,
  outOfZone = false,
  onDelete,
  onAddStage,
  onAddRestDay,
  onEditDistance,
  date = null,
  isToday = false,
  onAlertNavigate,
  onPress,
}: StageCardProps) {
  const { t, i18n } = useTranslation();
  const theme = useTheme();
  const [editingDistance, setEditingDistance] = useState(false);
  const [draft, setDraft] = useState('');
  const day = stage.dayNumber ?? index + 1;
  const heading = date
    ? formatStageDate(date, i18n.language)
    : t('trip.day', { day: stage.dayNumber ?? '?' });

  // Belt-and-suspenders to the stable FlatList key: if the underlying stage this
  // row renders changes identity (a position shift reused the instance, or SSE
  // reconciled the stage), drop any open distance editor so it can never commit
  // a stale draft onto a different stage than the one it was opened on (#1044).
  const key = stageKey(stage);
  useEffect(() => {
    setEditingDistance(false);
    setDraft('');
  }, [key]);

  // Distance re-splitting reroutes → hidden out of zone and on a rest day (0 km).
  const canEditDistance =
    !locked && !!onEditDistance && !stage.isRestDay && !outOfZone;
  const showFooter =
    !locked && (!!onAddStage || !!onAddRestDay || canEditDistance);

  function startEditDistance(): void {
    setDraft(String(Math.round(stage.distance ?? 0)));
    setEditingDistance(true);
  }

  function commitDistance(): void {
    const km = Number(draft.replace(',', '.'));
    // Keep the editor open on an invalid/empty value so the edit is not lost
    // silently; only close + commit a finite, positive distance.
    if (!Number.isFinite(km) || km <= 0) return;
    setEditingDistance(false);
    onEditDistance?.(index, km);
  }

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
          onPress ? t('trip.openStageA11y', { day }) : undefined
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
          accessibilityLabel={t('trip.deleteA11y', { day })}
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
          gap: theme.spacing.sm,
        }}
      >
        <StageDataBlocks stage={stage} onAlertNavigate={onAlertNavigate} />
        {showFooter ? (
          editingDistance ? (
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: theme.spacing.sm }}>
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
          ) : (
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: theme.spacing.sm }}>
              {onAddStage ? (
                <EditChip
                  icon={<Plus color={theme.colors.foreground} size={14} />}
                  label={t('trip.edit.addStage')}
                  a11yLabel={t('trip.edit.addStageA11y', { day })}
                  onPress={() => onAddStage(index)}
                  disabled={outOfZone}
                />
              ) : null}
              {onAddRestDay ? (
                <EditChip
                  icon={<Coffee color={theme.colors.foreground} size={14} />}
                  label={t('trip.edit.addRestDay')}
                  a11yLabel={t('trip.edit.addRestDayA11y', { day })}
                  onPress={() => onAddRestDay(index)}
                />
              ) : null}
              {canEditDistance ? (
                <EditChip
                  icon={<Pencil color={theme.colors.foreground} size={14} />}
                  label={t('trip.blocks.distanceKm', {
                    distance: Math.round(stage.distance ?? 0),
                  })}
                  a11yLabel={t('trip.edit.editDistanceA11y', { day })}
                  onPress={startEditDistance}
                />
              ) : null}
            </View>
          )
        ) : null}
      </View>
    </View>
  );
}
