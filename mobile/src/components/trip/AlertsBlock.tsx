import { Pressable, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import type { AlertData } from '@btp/core';
import { DataBlock } from './DataBlock';
import {
  SEVERITY_ORDER,
  alertDedupKey,
  groupBySeverity,
  severityStyle,
  visibleAlerts,
} from './alert-utils';
import { AlertTriangle } from '../ui/icons';
import { useTheme } from '../../theme';
import { useDismissedAlerts } from '../../store/dismissed-alerts';

// An ordered list of highlighted road stretches (each a list of [lat, lon]),
// matching the `navigate` action payload geometry.
type AlertSegments = [number, number][][];

interface AlertsBlockProps {
  alerts: AlertData[];
  // Routes a `navigate` alert action to the map segment (#1040 owns the map and
  // will pass a handler that highlights the concerned stretch). Optional: the
  // navigate button self-hides until a handler is wired.
  onNavigate?: (segments: AlertSegments) => void;
}

// Per-day alerts: deduplicated by code, grouped by severity (critical → warning
// → nudge), each with its dismiss / navigate action. Dismissal and dedup key on
// the stable AlertCode (never the wording); dismissed alerts are hidden.
export function AlertsBlock({ alerts, onNavigate }: AlertsBlockProps) {
  const { t } = useTranslation();
  const theme = useTheme();
  const dismissed = useDismissedAlerts((s) => s.dismissed);
  const dismiss = useDismissedAlerts((s) => s.dismiss);

  const shown = visibleAlerts(alerts, dismissed);
  const groups = groupBySeverity(shown);

  return (
    <DataBlock
      title={t('trip.blocks.alerts')}
      icon={<AlertTriangle color={theme.colors.mutedIcon} size={18} />}
      isEmpty={shown.length === 0}
      emptyLabel={t('trip.blocks.alertsEmpty')}
      count={shown.length}
    >
      {SEVERITY_ORDER.flatMap((severity) => {
        const bucket = groups[severity];
        if (bucket.length === 0) return [];
        const palette = severityStyle(severity, theme.scheme);
        return bucket.map((alert) => {
          const key = alertDedupKey(alert);
          const action = alert.action ?? null;
          const canNavigate =
            action?.kind === 'navigate' &&
            (action.payload.segments?.length ??
              (action.payload.lat != null && action.payload.lon != null ? 1 : 0)) >
              0;
          return (
            <View key={key} style={{ gap: theme.spacing.xs }}>
              <View
                style={{
                  backgroundColor: palette.bg,
                  borderRadius: theme.radius.md,
                  paddingHorizontal: theme.spacing.md,
                  paddingVertical: theme.spacing.sm,
                }}
              >
                <Text
                  style={{
                    color: palette.fg,
                    fontFamily: theme.fonts.sansMedium,
                    fontSize: 14,
                  }}
                >
                  {alert.message}
                </Text>
              </View>
              <View style={{ flexDirection: 'row', gap: theme.spacing.md }}>
                {action?.kind === 'dismiss' ? (
                  <Pressable
                    hitSlop={8}
                    onPress={() => dismiss(key)}
                    accessibilityRole="button"
                    accessibilityLabel={t('trip.blocks.alertDismiss')}
                  >
                    <Text
                      style={{
                        color: theme.colors.mutedForeground,
                        fontFamily: theme.fonts.sansMedium,
                        fontSize: 13,
                      }}
                    >
                      {action.label || t('trip.blocks.alertDismiss')}
                    </Text>
                  </Pressable>
                ) : null}
                {canNavigate && onNavigate ? (
                  <Pressable
                    hitSlop={8}
                    onPress={() => {
                      const { lat, lon, segments } = action!.payload;
                      if (segments && segments.length > 0) onNavigate(segments);
                      else if (typeof lat === 'number' && typeof lon === 'number')
                        onNavigate([[[lat, lon]]] as AlertSegments);
                    }}
                    accessibilityRole="button"
                    accessibilityLabel={t('trip.blocks.alertNavigate')}
                  >
                    <Text
                      style={{
                        color: theme.colors.accentBrand,
                        fontFamily: theme.fonts.sansMedium,
                        fontSize: 13,
                      }}
                    >
                      {action!.label || t('trip.blocks.alertNavigate')}
                    </Text>
                  </Pressable>
                ) : null}
              </View>
            </View>
          );
        });
      })}
    </DataBlock>
  );
}
