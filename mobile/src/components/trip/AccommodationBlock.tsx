import { useState } from 'react';
import { Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import type { AccommodationData } from '@btp/core';
import {
  ACCOMMODATION_RADIUS_STEP_KM,
  FILTERABLE_ACCOMMODATION_TYPES,
  MAX_ACCOMMODATION_RADIUS_KM,
  type FilterableAccommodationType,
} from '@btp/core/constants';
import { DataBlock } from './DataBlock';
import { Button, Input } from '../ui';
import { Plus, Search, Tent } from '../ui/icons';
import { useTheme } from '../../theme';

export interface ManualAccommodationInput {
  name: string;
  address: string;
  priceTotal: number | null;
  url: string | null;
}

// Candidates are revealed a page at a time (#1105).
const ACCOMMODATION_PAGE_SIZE = 5;

// The wire type is a raw string (backend contract, ADR-055) — "other" flags a
// manually-added entry and is excluded from FILTERABLE_ACCOMMODATION_TYPES
// (reserved for backend filtering), so it is listed separately here. Mirrors
// pwa/src/lib/accommodation-types.ts.
type KnownAccommodationType = FilterableAccommodationType | 'other';
const KNOWN_ACCOMMODATION_TYPES: readonly string[] = [
  ...FILTERABLE_ACCOMMODATION_TYPES,
  'other',
];
function isKnownAccommodationType(type: string): type is KnownAccommodationType {
  return KNOWN_ACCOMMODATION_TYPES.includes(type);
}

interface AccommodationBlockProps {
  accommodations: AccommodationData[];
  selectedAccommodation?: AccommodationData | null;
  // Editing affordances (#1045). Provided only in an editable context (stage
  // detail); when absent the block stays read-only (roadbook preview, tests).
  radiusKm?: number;
  // Locked / offline: selection and scan are disabled but still visible.
  disabled?: boolean;
  // Selecting / deselecting shifts the stage endpoint → the stage is re-routed
  // via Valhalla, unavailable out of zone. Widening the radius is a scan (no
  // reroute) and stays available.
  outOfZone?: boolean;
  onSelect?: (accIndex: number) => void;
  onDeselect?: () => void;
  onExpandRadius?: () => void;
  // Submit a hors-app accommodation (title/address/price/link); the backend
  // geocodes the address. Provided only in an editable stage. Returns true when
  // the backend accepted it (the form then closes).
  onAddManual?: (data: ManualAccommodationInput) => Promise<boolean>;
}

// Per-day accommodations: the selected one alone, or every candidate. Each row
// shows the name, type, price and distance to the stage end, plus a source
// badge for non-OSM entries. In an editable stage (#1045) each candidate can be
// selected, the selection cleared, and the search radius widened until the cap.
export function AccommodationBlock({
  accommodations,
  selectedAccommodation,
  radiusKm,
  disabled = false,
  outOfZone = false,
  onSelect,
  onDeselect,
  onExpandRadius,
  onAddManual,
}: AccommodationBlockProps) {
  const { t } = useTranslation();
  const theme = useTheme();
  const [showForm, setShowForm] = useState(false);
  const [formName, setFormName] = useState('');
  const [formAddress, setFormAddress] = useState('');
  const [formPrice, setFormPrice] = useState('');
  const [formUrl, setFormUrl] = useState('');
  const [submitting, setSubmitting] = useState(false);
  // Candidates ordered by proximity to the stage arrival (null distances last),
  // keeping each entry's original index so selection still targets the right one
  // after sorting. Paginated 5 at a time (#1105).
  const ranked = accommodations
    .map((acc, originalIndex) => ({ acc, originalIndex }))
    .sort(
      (a, b) =>
        (a.acc.distanceToEndPoint ?? Infinity) -
        (b.acc.distanceToEndPoint ?? Infinity),
    );
  const [visibleCount, setVisibleCount] = useState(ACCOMMODATION_PAGE_SIZE);
  const items = selectedAccommodation
    ? [{ acc: selectedAccommodation, originalIndex: -1 }]
    : ranked.slice(0, visibleCount);
  const hasMore = !selectedAccommodation && ranked.length > items.length;
  const editable = Boolean(onSelect);
  // Select / deselect reroute the stage: blocked out of zone (mirrors PoiBlock).
  const selectionDisabled = disabled || outOfZone;
  // Gate on the NEXT radius so we never scan past the cap if the constants change.
  const canExpand =
    editable &&
    !selectedAccommodation &&
    typeof radiusKm === 'number' &&
    radiusKm + ACCOMMODATION_RADIUS_STEP_KM <= MAX_ACCOMMODATION_RADIUS_KM;
  // Mirrors the web `formatPrice` (pwa/src/lib/formatters.ts): null when no
  // price is known (both bounds zero); a single figure — the upper bound `max` —
  // when the price is exact or the range has collapsed (min === max); otherwise
  // the estimated min–max range. Values are rounded to whole euros.
  const priceLabel = (acc: AccommodationData): string | null => {
    const min = acc.estimatedPriceMin;
    const max = acc.estimatedPriceMax;
    if (min === 0 && max === 0) return null;
    if (acc.isExactPrice || min === max) {
      return t('trip.blocks.accommodationPrice', { price: Math.round(max) });
    }
    return t('trip.blocks.accommodationPriceRange', {
      min: Math.round(min),
      max: Math.round(max),
    });
  };
  // The manual-add affordance must stay visible even with an empty candidate
  // list (adding by hand is precisely the empty-list case), so the block is only
  // "empty" — hiding its children behind the placeholder — when it also offers
  // no add affordance.
  const canAddManual = Boolean(onAddManual) && !selectedAccommodation;
  const isEmpty = items.length === 0 && !canAddManual;
  const canSubmit =
    formName.trim() !== '' && formAddress.trim() !== '' && !submitting;
  async function handleSubmit() {
    if (!onAddManual || !canSubmit) return;
    setSubmitting(true);
    const parsedPrice = parseFloat(formPrice);
    const ok = await onAddManual({
      name: formName.trim(),
      address: formAddress.trim(),
      priceTotal:
        Number.isFinite(parsedPrice) && parsedPrice >= 0 ? parsedPrice : null,
      url: formUrl.trim() === '' ? null : formUrl.trim(),
    });
    setSubmitting(false);
    if (ok) {
      setFormName('');
      setFormAddress('');
      setFormPrice('');
      setFormUrl('');
      setShowForm(false);
    }
  }
  return (
    <DataBlock
      title={t('trip.blocks.accommodation')}
      icon={<Tent color={theme.colors.mutedIcon} size={18} />}
      isEmpty={isEmpty}
      emptyLabel={t('trip.blocks.accommodationEmpty')}
      count={selectedAccommodation ? undefined : accommodations.length}
    >
      {items.length === 0 && canAddManual ? (
        <Text
          style={{
            color: theme.colors.mutedForeground,
            fontFamily: theme.fonts.sans,
            fontSize: 14,
          }}
        >
          {t('trip.blocks.accommodationEmpty')}
        </Text>
      ) : null}
      {items.map(({ acc, originalIndex }, i) => {
        const price = priceLabel(acc);
        const typeLabel = isKnownAccommodationType(acc.type)
          ? t(`config.type_${acc.type}` as const)
          : t('config.type_other');
        const meta = [
          typeLabel,
          price,
          acc.distanceToEndPoint != null
            ? t('trip.blocks.distanceKm', { distance: Math.round(acc.distanceToEndPoint) })
            : null,
        ]
          .filter(Boolean)
          .join(' · ');
        return (
          <View key={i} style={{ gap: theme.spacing.xs }}>
            <View
              style={{ flexDirection: 'row', alignItems: 'center', gap: theme.spacing.sm }}
            >
              <Text
                style={{
                  color: theme.colors.foreground,
                  fontFamily: theme.fonts.sansMedium,
                  fontSize: 14,
                  flexShrink: 1,
                }}
              >
                {acc.name}
              </Text>
              {selectedAccommodation ? (
                <Text
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
                  {t('trip.blocks.accommodationSelected')}
                </Text>
              ) : null}
            </View>
            <Text
              style={{
                color: theme.colors.mutedForeground,
                fontFamily: theme.fonts.mono,
                fontSize: 13,
              }}
            >
              {meta}
              {acc.source === 'manual'
                ? ` · ${t('trip.blocks.accommodationSourceManual')}`
                : acc.source && acc.source !== 'osm'
                  ? ' · DataTourisme'
                  : ''}
            </Text>
            {acc.address ? (
              <Text
                style={{
                  color: theme.colors.mutedForeground,
                  fontFamily: theme.fonts.sans,
                  fontSize: 13,
                }}
              >
                {acc.address}
              </Text>
            ) : null}
            {editable && selectedAccommodation ? (
              <Button
                variant="secondary"
                size="sm"
                label={t('trip.blocks.accommodationDeselect')}
                disabled={selectionDisabled}
                onPress={onDeselect}
              />
            ) : null}
            {editable && !selectedAccommodation ? (
              <Button
                variant="secondary"
                size="sm"
                label={t('trip.blocks.accommodationSelect')}
                disabled={selectionDisabled}
                onPress={() => onSelect?.(originalIndex)}
              />
            ) : null}
          </View>
        );
      })}
      {hasMore ? (
        <Button
          variant="ghost"
          size="sm"
          label={t('trip.blocks.accommodationMore', {
            count: ACCOMMODATION_PAGE_SIZE,
          })}
          onPress={() =>
            setVisibleCount((n) => n + ACCOMMODATION_PAGE_SIZE)
          }
        />
      ) : null}
      {canExpand ? (
        <Button
          variant="ghost"
          size="sm"
          label={t('trip.blocks.accommodationExpandRadius', {
            step: ACCOMMODATION_RADIUS_STEP_KM,
          })}
          icon={<Search color={theme.colors.foreground} size={14} />}
          disabled={disabled}
          onPress={onExpandRadius}
        />
      ) : null}
      {editable && outOfZone ? (
        <Text
          style={{
            color: theme.colors.mutedForeground,
            fontFamily: theme.fonts.sans,
            fontSize: 13,
          }}
        >
          {t('trip.blocks.accommodationOutOfZone')}
        </Text>
      ) : null}
      {onAddManual && !selectedAccommodation ? (
        showForm ? (
          <View style={{ gap: theme.spacing.sm }}>
            <Input
              value={formName}
              onChangeText={setFormName}
              placeholder={t('trip.blocks.accommodationManualNamePlaceholder')}
              autoFocus
            />
            <Input
              value={formAddress}
              onChangeText={setFormAddress}
              placeholder={t('trip.blocks.accommodationManualAddressPlaceholder')}
            />
            <Input
              value={formPrice}
              onChangeText={setFormPrice}
              placeholder={t('trip.blocks.accommodationManualPricePlaceholder')}
              keyboardType="numeric"
            />
            <Input
              value={formUrl}
              onChangeText={setFormUrl}
              placeholder={t('trip.blocks.accommodationManualUrlPlaceholder')}
              autoCapitalize="none"
              keyboardType="url"
            />
            <View style={{ flexDirection: 'row', gap: theme.spacing.sm }}>
              <Button
                variant="primary"
                size="sm"
                label={t('trip.blocks.accommodationManualSave')}
                disabled={!canSubmit || selectionDisabled}
                onPress={handleSubmit}
              />
              <Button
                variant="ghost"
                size="sm"
                label={t('trip.blocks.accommodationManualCancel')}
                disabled={submitting}
                onPress={() => setShowForm(false)}
              />
            </View>
          </View>
        ) : (
          <Button
            variant="secondary"
            size="sm"
            label={t('trip.blocks.accommodationAddManual')}
            icon={<Plus color={theme.colors.foreground} size={14} />}
            disabled={selectionDisabled}
            onPress={() => setShowForm(true)}
          />
        )
      ) : null}
    </DataBlock>
  );
}
