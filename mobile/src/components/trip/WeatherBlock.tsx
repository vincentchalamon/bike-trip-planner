import { Text } from 'react-native';
import { useTranslation } from 'react-i18next';
import type { WeatherData } from '@btp/core';
import { DataBlock } from './DataBlock';
import { CloudRain } from '../ui/icons';
import { useTheme } from '../../theme';

// Per-day weather. Placeholder content (description + temp range); #1038 renders
// the icon, wind and comfort index.
export function WeatherBlock({ weather }: { weather: WeatherData | null }) {
  const { t } = useTranslation();
  const theme = useTheme();
  return (
    <DataBlock
      title={t('trip.blocks.weather')}
      icon={<CloudRain color={theme.colors.mutedIcon} size={18} />}
      isEmpty={weather === null}
      emptyLabel={t('trip.blocks.weatherEmpty')}
    >
      {weather ? (
        <>
          <Text
            style={{
              color: theme.colors.foreground,
              fontFamily: theme.fonts.sans,
              fontSize: 14,
            }}
          >
            {weather.description}
          </Text>
          <Text
            style={{
              color: theme.colors.mutedForeground,
              fontFamily: theme.fonts.mono,
              fontSize: 13,
            }}
          >
            {t('trip.blocks.weatherTemp', {
              min: Math.round(weather.tempMin),
              max: Math.round(weather.tempMax),
            })}
          </Text>
        </>
      ) : null}
    </DataBlock>
  );
}
