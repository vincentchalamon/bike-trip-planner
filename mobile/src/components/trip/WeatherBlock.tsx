import { Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import type { WeatherData } from '@btp/core';
import { DataBlock } from './DataBlock';
import { CloudRain } from '../ui/icons';
import { useTheme } from '../../theme';

// Per-day weather: description + temp range, wind and precipitation probability.
export function WeatherBlock({ weather }: { weather: WeatherData | null }) {
  const { t } = useTranslation();
  const theme = useTheme();
  const muted = {
    color: theme.colors.mutedForeground,
    fontFamily: theme.fonts.mono,
    fontSize: 13,
  } as const;
  return (
    <DataBlock
      title={t('trip.blocks.weather')}
      icon={<CloudRain color={theme.colors.mutedIcon} size={18} />}
      isEmpty={weather === null}
      emptyLabel={t('trip.blocks.weatherEmpty')}
    >
      {weather ? (
        <View style={{ gap: theme.spacing.xs }}>
          <Text
            style={{
              color: theme.colors.foreground,
              fontFamily: theme.fonts.sans,
              fontSize: 14,
            }}
          >
            {weather.description}
          </Text>
          <Text style={muted}>
            {t('trip.blocks.weatherTemp', {
              min: Math.round(weather.tempMin),
              max: Math.round(weather.tempMax),
            })}
          </Text>
          <Text style={muted}>
            {t('trip.blocks.weatherWind', {
              speed: Math.round(weather.windSpeed),
              direction: weather.windDirection,
            })}
          </Text>
          <Text style={muted}>
            {t('trip.blocks.weatherPrecip', {
              prob: Math.round(weather.precipitationProbability),
            })}
          </Text>
        </View>
      ) : null}
    </DataBlock>
  );
}
