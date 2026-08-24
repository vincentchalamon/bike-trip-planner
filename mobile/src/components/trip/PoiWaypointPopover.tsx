import { Pressable, StyleSheet, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { Button } from '../ui';
import { MapPin, Plus, X } from '../ui/icons';
import { useTheme } from '../../theme';
import type { MapMarker } from '../map/map-utils';

// Popover shown when a POI marker on the stage map is tapped (#1179), mirroring
// pwa's poi-popover.tsx "add to itinerary" affordance: the POI name/type, an
// "Add to itinerary" action (routes the stage through the POI via
// runAddPoiWaypoint) and a close button. `disabled` gates the add in the
// roadbook's read-only / degraded state — the reroute needs the API and the zone.
export function PoiWaypointPopover({
  poi,
  onAdd,
  onClose,
  disabled = false,
}: {
  poi: MapMarker;
  onAdd: () => void;
  onClose: () => void;
  disabled?: boolean;
}) {
  const { t } = useTranslation();
  const theme = useTheme();
  const name = poi.name || t('trip.poiWaypoint.type');

  return (
    <View
      style={[
        styles.card,
        {
          backgroundColor: theme.colors.card,
          borderColor: theme.colors.border,
          borderRadius: theme.radius.xl,
          padding: theme.spacing.base,
          gap: theme.spacing.sm,
          ...theme.shadows.medium,
        },
      ]}
    >
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={t('trip.poiWaypoint.close')}
        onPress={onClose}
        hitSlop={8}
        style={styles.close}
      >
        <X color={theme.colors.mutedForeground} size={18} />
      </Pressable>

      <View style={styles.head}>
        <MapPin color={theme.colors.accentInk} size={18} />
        <View style={{ flex: 1 }}>
          <Text
            numberOfLines={2}
            style={{
              color: theme.colors.foreground,
              fontFamily: theme.fonts.serif,
              fontSize: 15,
            }}
          >
            {name}
          </Text>
          <Text
            style={{
              color: theme.colors.mutedForeground,
              fontFamily: theme.fonts.sansMedium,
              fontSize: 12,
            }}
          >
            {t('trip.poiWaypoint.type')}
          </Text>
        </View>
      </View>

      <Button
        label={t('trip.poiWaypoint.add')}
        size="sm"
        fullWidth
        disabled={disabled}
        icon={<Plus color="#ffffff" size={16} />}
        onPress={onAdd}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    position: 'absolute',
    left: 12,
    right: 12,
    bottom: 12,
    borderWidth: StyleSheet.hairlineWidth,
  },
  close: { position: 'absolute', top: 8, right: 8, zIndex: 1 },
  head: { flexDirection: 'row', alignItems: 'flex-start', gap: 8, paddingRight: 24 },
});
