import { Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import type { AccommodationData } from '@btp/core';
import {
  ACCOMMODATION_RADIUS_STEP_KM,
  MAX_ACCOMMODATION_RADIUS_KM,
} from '@btp/core/constants';
import { DataBlock } from './DataBlock';
import { Button } from '../ui';
import { Search, Tent } from '../ui/icons';
import { useTheme } from '../../theme';

interface AccommodationBlockProps {
  accommodations: AccommodationData[];
  selectedAccommodation?: AccommodationData | null;
  // Editing affordances (#1045). Provided only in an editable context (stage
  // detail); when absent the block stays read-only (roadbook preview, tests).
  radiusKm?: number;
  // Locked / offline: selection and scan are disabled but still visible.
  disabled?: boolean;
  onSelect?: (accIndex: number) => void;
  onDeselect?: () => void;
  onExpandRadius?: () => void;
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
  onSelect,
  onDeselect,
  onExpandRadius,
}: AccommodationBlockProps) {
  const { t } = useTranslation();
  const theme = useTheme();
  const items = selectedAccommodation ? [selectedAccommodation] : accommodations;
  const editable = Boolean(onSelect);
  const canExpand =
    editable &&
    !selectedAccommodation &&
    typeof radiusKm === 'number' &&
    radiusKm < MAX_ACCOMMODATION_RADIUS_KM;
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
  return (
    <DataBlock
      title={t('trip.blocks.accommodation')}
      icon={<Tent color={theme.colors.mutedIcon} size={18} />}
      isEmpty={items.length === 0}
      emptyLabel={t('trip.blocks.accommodationEmpty')}
      count={selectedAccommodation ? undefined : accommodations.length}
    >
      {items.map((acc, i) => {
        const price = priceLabel(acc);
        const meta = [
          acc.type,
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
              {acc.source && acc.source !== 'osm' ? ' · DataTourisme' : ''}
            </Text>
            {editable && selectedAccommodation ? (
              <Button
                variant="secondary"
                size="sm"
                label={t('trip.blocks.accommodationDeselect')}
                disabled={disabled}
                onPress={onDeselect}
              />
            ) : null}
            {editable && !selectedAccommodation ? (
              <Button
                variant="secondary"
                size="sm"
                label={t('trip.blocks.accommodationSelect')}
                disabled={disabled}
                onPress={() => onSelect?.(i)}
              />
            ) : null}
          </View>
        );
      })}
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
    </DataBlock>
  );
}
