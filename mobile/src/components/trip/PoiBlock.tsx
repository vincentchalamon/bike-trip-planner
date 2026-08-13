import { Text } from 'react-native';
import { useTranslation } from 'react-i18next';
import type { PoiData } from '@btp/core';
import { DataBlock } from './DataBlock';
import { MapPin } from '../ui/icons';
import { useTheme } from '../../theme';

// Per-day points of interest. Placeholder content (name list); #1038 renders
// category, distance and the "add as waypoint" action.
export function PoiBlock({ pois }: { pois: PoiData[] }) {
  const { t } = useTranslation();
  const theme = useTheme();
  return (
    <DataBlock
      title={t('trip.blocks.poi')}
      icon={<MapPin color={theme.colors.mutedIcon} size={18} />}
      isEmpty={pois.length === 0}
      emptyLabel={t('trip.blocks.poiEmpty')}
      count={pois.length}
    >
      {pois.map((poi, i) => (
        <Text
          key={i}
          style={{
            color: theme.colors.foreground,
            fontFamily: theme.fonts.sans,
            fontSize: 14,
          }}
        >
          {poi.name}
        </Text>
      ))}
    </DataBlock>
  );
}
