import { useState } from 'react';
import { Pressable, ScrollView, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import type { WeatherData } from '@btp/core';
import { DataBlock } from './DataBlock';
import { StageWeatherModal } from './StageWeatherModal';
import { CloudRain } from '../ui/icons';
import { useTheme } from '../../theme';

/** Gusts are only worth a line when notably above the mean wind. */
const GUST_HIGHLIGHT_DELTA_KMH = 15;

// Per-day weather: description + temp range, wind and precipitation, plus the
// advanced fields (feels-like, gusts, rain mm), an hourly strip and a full
// hour-by-hour detail modal when the hourly series is available.
export function WeatherBlock({ weather }: { weather: WeatherData | null }) {
  const { t } = useTranslation();
  const theme = useTheme();
  const [detailOpen, setDetailOpen] = useState(false);
  const muted = {
    color: theme.colors.mutedForeground,
    fontFamily: theme.fonts.mono,
    fontSize: 13,
  } as const;

  const hasHourly = (weather?.hourly?.length ?? 0) > 0;

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
          {hasHourly ? (
            <Text style={muted}>
              {t('trip.blocks.weatherFeelsLike', {
                min: Math.round(weather.apparentTempMin),
                max: Math.round(weather.apparentTempMax),
              })}
            </Text>
          ) : null}
          <Text style={muted}>
            {t('trip.blocks.weatherWind', {
              speed: Math.round(weather.windSpeed),
              direction: weather.windDirection,
            })}
          </Text>
          {hasHourly &&
          weather.windGusts >= weather.windSpeed + GUST_HIGHLIGHT_DELTA_KMH ? (
            <Text style={muted}>
              {t('trip.blocks.weatherGusts', {
                speed: Math.round(weather.windGusts),
              })}
            </Text>
          ) : null}
          {hasHourly && weather.precipitationMm > 0 ? (
            <Text style={muted}>
              {t('trip.blocks.weatherRainMm', { mm: weather.precipitationMm })}
            </Text>
          ) : (
            <Text style={muted}>
              {t('trip.blocks.weatherPrecip', {
                prob: Math.round(weather.precipitationProbability),
              })}
            </Text>
          )}

          {hasHourly ? (
            <>
              <ScrollView
                horizontal
                showsHorizontalScrollIndicator={false}
                style={{ marginTop: theme.spacing.xs }}
              >
                <View style={{ flexDirection: 'row', gap: theme.spacing.sm }}>
                  {weather.hourly.map((h) => (
                    <View
                      key={h.hour}
                      accessibilityLabel={t('trip.blocks.weatherHourlyRow', {
                        hour: h.hour % 24,
                        temp: Math.round(h.temp),
                        feels: Math.round(h.apparentTemp),
                        mm: h.precipitationMm,
                        wind: Math.round(h.windSpeed),
                      })}
                      style={{ alignItems: 'center', minWidth: 44 }}
                    >
                      <Text style={muted}>{h.hour % 24}h</Text>
                      <Text
                        style={{
                          color: theme.colors.foreground,
                          fontFamily: theme.fonts.mono,
                          fontSize: 13,
                        }}
                      >
                        {Math.round(h.temp)}°
                      </Text>
                    </View>
                  ))}
                </View>
              </ScrollView>

              <Pressable
                onPress={() => setDetailOpen(true)}
                accessibilityRole="button"
                accessibilityLabel={t('trip.blocks.weatherHourlyDetail')}
                style={{ minHeight: 44, justifyContent: 'center' }}
              >
                <Text
                  style={{
                    color: theme.colors.foreground,
                    fontFamily: theme.fonts.sansMedium,
                    fontSize: 13,
                  }}
                >
                  {t('trip.blocks.weatherHourlyDetail')}
                </Text>
              </Pressable>

              <StageWeatherModal
                visible={detailOpen}
                hourly={weather.hourly}
                onClose={() => setDetailOpen(false)}
              />
            </>
          ) : null}
        </View>
      ) : null}
    </DataBlock>
  );
}
