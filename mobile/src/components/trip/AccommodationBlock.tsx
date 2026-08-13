import { Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import type { AccommodationData } from '@btp/core';
import { DataBlock } from './DataBlock';
import { Tent } from '../ui/icons';
import { useTheme } from '../../theme';

interface AccommodationBlockProps {
  accommodations: AccommodationData[];
  selectedAccommodation?: AccommodationData | null;
}

// Per-day accommodations: the selected one alone, or every candidate. Each row
// shows the name, type, price and distance to the stage end, plus a source
// badge for non-OSM entries.
export function AccommodationBlock({
  accommodations,
  selectedAccommodation,
}: AccommodationBlockProps) {
  const { t } = useTranslation();
  const theme = useTheme();
  const items = selectedAccommodation ? [selectedAccommodation] : accommodations;
  // A single figure when exact, otherwise the estimated min–max range (mirrors
  // the web formatPrice); null when no price is known.
  const priceLabel = (acc: AccommodationData): string | null => {
    if (acc.isExactPrice) {
      return t('trip.blocks.accommodationPrice', {
        price: Math.round(acc.estimatedPriceMin),
      });
    }
    if (acc.estimatedPriceMin > 0 || acc.estimatedPriceMax > 0) {
      return t('trip.blocks.accommodationPriceRange', {
        min: Math.round(acc.estimatedPriceMin),
        max: Math.round(acc.estimatedPriceMax),
      });
    }
    return null;
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
          acc.distanceToEndPoint
            ? t('trip.blocks.distanceKm', { distance: Math.round(acc.distanceToEndPoint) })
            : null,
        ]
          .filter(Boolean)
          .join(' · ');
        return (
          <View key={i}>
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
          </View>
        );
      })}
    </DataBlock>
  );
}
