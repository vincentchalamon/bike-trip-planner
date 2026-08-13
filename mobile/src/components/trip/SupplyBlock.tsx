import { Text } from 'react-native';
import { useTranslation } from 'react-i18next';
import type { SupplyMarkerData } from '@btp/core';
import { DataBlock } from './DataBlock';
import { Route } from '../ui/icons';
import { useTheme } from '../../theme';

// Per-day supply timeline (water / food points), ordered by distance along the
// route: each marker shows its km mark plus the water / food counts.
export function SupplyBlock({ supplyTimeline }: { supplyTimeline: SupplyMarkerData[] }) {
  const { t } = useTranslation();
  const theme = useTheme();
  const ordered = [...supplyTimeline].sort(
    (a, b) => a.distanceFromStart - b.distanceFromStart,
  );
  return (
    <DataBlock
      title={t('trip.blocks.supply')}
      icon={<Route color={theme.colors.mutedIcon} size={18} />}
      isEmpty={ordered.length === 0}
      emptyLabel={t('trip.blocks.supplyEmpty')}
      count={ordered.length}
    >
      {ordered.map((marker, i) => (
        <Text
          key={i}
          style={{
            color: theme.colors.foreground,
            fontFamily: theme.fonts.mono,
            fontSize: 13,
          }}
        >
          {t('trip.blocks.supplyMarkerAt', {
            distance: Math.round(marker.distanceFromStart),
            water: marker.water.length,
            food: marker.food.length,
          })}
        </Text>
      ))}
    </DataBlock>
  );
}
