import { Modal, Pressable, ScrollView, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import Svg, { Path, Rect, Line } from 'react-native-svg';
import {
  buildWeatherSeries,
  rainBars,
  tempPath,
  type HourlyWeatherData,
  type PlotGeometry,
} from '@btp/core';
import { useTheme } from '../../theme';

const GEO: PlotGeometry = {
  width: 800,
  height: 200,
  padLeft: 8,
  padRight: 8,
  padTop: 12,
  padBottom: 28,
};

interface StageWeatherModalProps {
  visible: boolean;
  hourly: HourlyWeatherData[];
  onClose: () => void;
}

/**
 * Full-screen hourly weather detail. The graph is decorative; the row list below
 * is the accessible source of truth (one labelled row per hour), so the data is
 * reachable without depending on the touch graph.
 */
export function StageWeatherModal({
  visible,
  hourly,
  onClose,
}: StageWeatherModalProps) {
  const { t } = useTranslation();
  const theme = useTheme();
  const series = buildWeatherSeries(hourly);

  return (
    <Modal
      visible={visible}
      animationType="slide"
      onRequestClose={onClose}
      accessibilityViewIsModal
      transparent={false}
    >
      <View
        style={{
          flex: 1,
          backgroundColor: theme.colors.background,
          padding: theme.spacing.md,
        }}
      >
        <View
          style={{
            flexDirection: 'row',
            justifyContent: 'space-between',
            alignItems: 'center',
            marginBottom: theme.spacing.sm,
          }}
        >
          <Text
            style={{
              color: theme.colors.foreground,
              fontFamily: theme.fonts.sansMedium,
              fontSize: 16,
            }}
          >
            {t('trip.blocks.weatherHourlyTitle')}
          </Text>
          <Pressable
            onPress={onClose}
            accessibilityRole="button"
            accessibilityLabel={t('trip.blocks.weatherClose')}
            style={{
              minHeight: 44,
              minWidth: 44,
              justifyContent: 'center',
              alignItems: 'flex-end',
            }}
          >
            <Text style={{ color: theme.colors.foreground, fontSize: 16 }}>
              {t('trip.blocks.weatherClose')}
            </Text>
          </Pressable>
        </View>

        {series ? (
          <Svg
            width="100%"
            height={120}
            viewBox={`0 0 ${GEO.width} ${GEO.height}`}
            preserveAspectRatio="none"
            accessibilityElementsHidden
            importantForAccessibility="no-hide-descendants"
          >
            {rainBars(series, GEO).map((b) => (
              <Rect
                key={`rain-${b.hour}`}
                x={b.x}
                y={b.y}
                width={b.width}
                height={b.height}
                fill={theme.colors.mutedIcon}
                fillOpacity={0.5}
              />
            ))}
            <Path
              d={tempPath(series, GEO, 'apparentTemp')}
              fill="none"
              stroke={theme.colors.mutedForeground}
              strokeWidth={2}
              strokeDasharray="4 3"
            />
            <Path
              d={tempPath(series, GEO, 'temp')}
              fill="none"
              stroke={theme.colors.foreground}
              strokeWidth={2.5}
            />
            <Line
              x1={GEO.padLeft}
              y1={GEO.height - GEO.padBottom}
              x2={GEO.width - GEO.padRight}
              y2={GEO.height - GEO.padBottom}
              stroke={theme.colors.border}
              strokeWidth={1}
            />
          </Svg>
        ) : null}

        <ScrollView style={{ marginTop: theme.spacing.sm }}>
          {hourly.map((h) => (
            <View
              key={h.hour}
              accessibilityLabel={t('trip.blocks.weatherHourlyRow', {
                hour: h.hour % 24,
                temp: Math.round(h.temp),
                feels: Math.round(h.apparentTemp),
                mm: h.precipitationMm,
                wind: Math.round(h.windSpeed),
              })}
              style={{
                flexDirection: 'row',
                justifyContent: 'space-between',
                paddingVertical: 6,
                borderBottomWidth: 1,
                borderBottomColor: theme.colors.border,
              }}
            >
              <Text
                style={{
                  color: theme.colors.mutedForeground,
                  fontFamily: theme.fonts.mono,
                  fontSize: 13,
                }}
              >
                {h.hour % 24}h
              </Text>
              <Text
                style={{
                  color: theme.colors.foreground,
                  fontFamily: theme.fonts.mono,
                  fontSize: 13,
                }}
              >
                {Math.round(h.temp)}° ({Math.round(h.apparentTemp)}°) ·{' '}
                {h.precipitationMm}mm · {Math.round(h.windSpeed)}km/h
              </Text>
            </View>
          ))}
        </ScrollView>
      </View>
    </Modal>
  );
}
