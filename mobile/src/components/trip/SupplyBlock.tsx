import { Text } from 'react-native';
import { useTranslation } from 'react-i18next';
import type { SupplyMarkerData } from '@btp/core';
import { DataBlock } from './DataBlock';
import { Route } from '../ui/icons';
import { useTheme } from '../../theme';

// Per-day supply timeline (water / food points). Placeholder content (per-marker
// water/food counts); #1038 renders the distance-along-route timeline.
export function SupplyBlock({ supplyTimeline }: { supplyTimeline: SupplyMarkerData[] }) {
  const { t } = useTranslation();
  const theme = useTheme();
  return (
    <DataBlock
      title={t('trip.blocks.supply')}
      icon={<Route color={theme.colors.mutedIcon} size={18} />}
      isEmpty={supplyTimeline.length === 0}
      emptyLabel={t('trip.blocks.supplyEmpty')}
      count={supplyTimeline.length}
    >
      {supplyTimeline.map((marker, i) => (
        <Text
          key={i}
          style={{
            color: theme.colors.foreground,
            fontFamily: theme.fonts.sans,
            fontSize: 14,
          }}
        >
          {t('trip.blocks.supplyMarker', {
            water: marker.water.length,
            food: marker.food.length,
          })}
        </Text>
      ))}
    </DataBlock>
  );
}
