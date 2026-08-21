import { type ComponentType } from 'react';
import { ActivityIndicator, Linking, Pressable, Text, View } from 'react-native';
import { useTranslation } from 'react-i18next';
import { useTheme, type Theme } from '../../theme';
import {
  useInRideSearch,
  type InRideRecap,
  type SearchPosition,
} from '../../hooks/use-in-ride-search';
import type { ForegroundLocation } from '../../hooks/use-foreground-location';
import type {
  InRidePoiCategory,
  NearbyPoiSuggestion,
} from '../../api/nearby-pois';
import {
  AlertTriangle,
  Clock,
  Cross,
  Droplet,
  ExternalLink,
  Phone,
  Search,
  ShoppingCart,
  Tent,
  TrainFront,
  UtensilsCrossed,
  Wrench,
  Zap,
} from '../ui/icons';

type IconType = ComponentType<{ color?: string; size?: number }>;

// The eight guided intents in display order (ADR-048 §3), each mapped to its
// chip icon. `resupply` (ravitaillement) is included between food and mechanic.
const INTENTS: ReadonlyArray<{ category: InRidePoiCategory; Icon: IconType }> = [
  { category: 'water', Icon: Droplet },
  { category: 'shelter', Icon: Tent },
  { category: 'food', Icon: UtensilsCrossed },
  { category: 'resupply', Icon: ShoppingCart },
  { category: 'mechanic', Icon: Wrench },
  { category: 'health', Icon: Cross },
  { category: 'train', Icon: TrainFront },
  { category: 'charging', Icon: Zap },
];

const CATEGORY_ICON: Record<InRidePoiCategory, IconType> = {
  water: Droplet,
  shelter: Tent,
  food: UtensilsCrossed,
  resupply: ShoppingCart,
  mechanic: Wrench,
  health: Cross,
  train: TrainFront,
  charging: Zap,
};

/**
 * Guard before any `Linking.openURL(poi.deeplink)` handoff — mirrors the web
 * client's `safeUrlSchema` gate (`pwa/src/lib/api/client.ts`). `nearby-pois.ts`
 * casts the response with no runtime validation, and on native a non-http(s)
 * scheme (e.g. `intent://`) reaches `Intent.ACTION_VIEW` directly, which could
 * resolve against another app's exported intent filter. Defence-in-depth
 * against a tampered/corrupt persisted `deeplink` field only.
 */
function isSafeDeeplink(url: string): boolean {
  return /^https?:\/\//i.test(url);
}

/**
 * Guard before `Linking.openURL(`tel:${poi.phone}`)` — `poi.phone` is
 * verbatim, publicly-editable OSM tag text (`OsmContactTags::phone()` does
 * not reformat it), so it reaches the dialer's `Intent.ACTION_VIEW` sink
 * unsanitized. Accepts only digits and common phone punctuation.
 */
function isSafePhone(phone: string): boolean {
  return /^[\d\s+()-]+$/.test(phone);
}

/** Compact meters -> `450 m` / `2.3 km` (units are near-universal, no i18n). */
function formatDistance(meters: number): string {
  if (!Number.isFinite(meters) || meters < 0) return '—';
  if (meters < 1000) return `${Math.round(meters)} m`;
  return `${(meters / 1000).toFixed(1).replace(/\.0$/, '')} km`;
}

/**
 * Wall-clock HH:MM from an RFC 3339 closing time, read straight off the string
 * (not via Date) so it stays the POI-local time the backend encoded and never
 * shifts with the device timezone — the CI timezone trap that bites Date-based
 * formatting.
 */
function formatClosingTime(iso: string | null | undefined): string {
  if (!iso) return '';
  const match = /T(\d{2}:\d{2})/.exec(iso);
  return match ? match[1] : '';
}

/**
 * Guided in-ride POI finder (ADR-048, #1150) — mounted in the `poiPanel` slot of
 * {@link InRideView}. Eight intent chips search from the rider's real GPS fix
 * (#1149); results render as themed cards with distance, opening status (+ an
 * "unverified hours" warning), detour and a one-tap maps handoff. No free text,
 * no token, no LLM. Structure only — the 08-in-ride maquette polish is #1094.
 */
export function InRidePanel({
  tripId,
  location,
  stageDay,
}: {
  tripId: string;
  location: ForegroundLocation;
  // Optional active day, forwarded to the backend for the detour approximation.
  stageDay?: number | null;
}) {
  const { t } = useTranslation();
  const theme = useTheme();
  const { isSearching, errorKey, recap, activeCategory, canWiden, search, widen } =
    useInRideSearch(tripId, stageDay);

  const position = location.position;
  const coords: SearchPosition | null = position
    ? { lat: position.latitude, lon: position.longitude }
    : null;
  const disabled = isSearching || coords === null;

  return (
    <View style={{ paddingHorizontal: theme.spacing.base, gap: theme.spacing.md }}>
      <View
        accessibilityRole="menu"
        accessibilityLabel={t('trip.inRide.chipsGroupA11y')}
        style={{ flexDirection: 'row', flexWrap: 'wrap', gap: theme.spacing.sm }}
      >
        {INTENTS.map(({ category, Icon }) => {
          const selected = activeCategory === category;
          return (
            <Pressable
              key={category}
              accessibilityRole="button"
              accessibilityLabel={t(`trip.inRide.search.${category}`)}
              accessibilityState={{ disabled, selected }}
              disabled={disabled}
              onPress={() => coords && search(category, coords)}
              style={{
                flexDirection: 'row',
                alignItems: 'center',
                gap: theme.spacing.xs,
                paddingVertical: theme.spacing.sm,
                paddingHorizontal: theme.spacing.md,
                borderRadius: theme.radius.full,
                borderWidth: 1,
                borderColor: selected ? theme.colors.brandFill : theme.colors.border,
                backgroundColor: selected ? theme.colors.brandLight : theme.colors.card,
                opacity: disabled ? 0.5 : 1,
              }}
            >
              <Icon color={theme.colors.brand} size={16} />
              <Text
                style={{
                  color: theme.colors.foreground,
                  fontFamily: theme.fonts.sansMedium,
                  fontSize: 13,
                }}
              >
                {t(`trip.inRide.search.${category}`)}
              </Text>
            </Pressable>
          );
        })}
      </View>

      {isSearching ? (
        <View
          accessibilityRole="alert"
          accessibilityLabel={t('trip.inRide.searching')}
          style={{ flexDirection: 'row', alignItems: 'center', gap: theme.spacing.sm }}
        >
          <ActivityIndicator size="small" color={theme.colors.brand} />
          <Text
            style={{
              color: theme.colors.mutedForeground,
              fontFamily: theme.fonts.sans,
              fontSize: 13,
            }}
          >
            {t('trip.inRide.searching')}
          </Text>
        </View>
      ) : null}

      {errorKey ? (
        <Text
          accessibilityRole="alert"
          style={{
            color: theme.colors.destructive,
            fontFamily: theme.fonts.sansMedium,
            fontSize: 13,
          }}
        >
          {t(`trip.inRide.${errorKey}`)}
        </Text>
      ) : null}

      {recap ? (
        <RecapBlock
          recap={recap}
          canWiden={canWiden}
          onWiden={() => coords && widen(coords)}
          theme={theme}
        />
      ) : null}
    </View>
  );
}

function RecapBlock({
  recap,
  canWiden,
  onWiden,
  theme,
}: {
  recap: InRideRecap;
  canWiden: boolean;
  onWiden: () => void;
  theme: Theme;
}) {
  const { t } = useTranslation();
  const shown = recap.pois.length;

  let recapText: string;
  if (recap.outOfCoverage) {
    recapText = t('trip.inRide.recap.outOfCoverage');
  } else if (shown > 0) {
    recapText =
      recap.totalFound > shown
        ? t('trip.inRide.recap.foundTruncated', {
            count: shown,
            total: recap.totalFound,
          })
        : t('trip.inRide.recap.found', { count: shown });
  } else if (recap.capReached) {
    recapText = t('trip.inRide.recap.capReached', { count: recap.totalFound });
  } else {
    recapText = t('trip.inRide.recap.none');
  }

  return (
    <View style={{ gap: theme.spacing.sm }}>
      <Text
        accessibilityRole="text"
        style={{
          color: theme.colors.foreground,
          fontFamily: theme.fonts.sansMedium,
          fontSize: 14,
        }}
      >
        {recapText}
      </Text>

      {recap.pois.map((poi, index) => (
        <PoiCard key={`${poi.deeplink}-${index}`} poi={poi} theme={theme} />
      ))}

      {canWiden ? (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t('trip.inRide.widenSearch')}
          onPress={onWiden}
          style={{
            flexDirection: 'row',
            alignItems: 'center',
            alignSelf: 'flex-start',
            gap: theme.spacing.xs,
            paddingVertical: theme.spacing.sm,
            paddingHorizontal: theme.spacing.md,
            borderRadius: theme.radius.full,
            borderWidth: 1,
            borderColor: theme.colors.border,
            backgroundColor: theme.colors.card,
          }}
        >
          <Search color={theme.colors.foreground} size={14} />
          <Text
            style={{
              color: theme.colors.foreground,
              fontFamily: theme.fonts.sansMedium,
              fontSize: 13,
            }}
          >
            {t('trip.inRide.widenSearch')}
          </Text>
        </Pressable>
      ) : null}
    </View>
  );
}

function PoiCard({ poi, theme }: { poi: NearbyPoiSuggestion; theme: Theme }) {
  const { t } = useTranslation();
  const Icon = CATEGORY_ICON[poi.category] ?? Droplet;
  const closesAt = formatClosingTime(poi.closes_at);
  const hasOpeningHours =
    !!poi.opening_hours_today && poi.opening_hours_today.trim() !== '';
  const detour = poi.detour_m;

  const warningText =
    poi.warning === 'closes_soon'
      ? t('trip.inRide.warning.closesSoon', { minutes: poi.warning_minutes ?? 0 })
      : poi.warning === 'far_from_route'
        ? t('trip.inRide.warning.farFromRoute')
        : poi.warning === 'hours_unverified'
          ? t('trip.inRide.noOpeningHours')
          : null;

  return (
    <View
      accessibilityLabel={poi.name}
      style={{
        gap: theme.spacing.sm,
        padding: theme.spacing.md,
        borderRadius: theme.radius.xl,
        borderWidth: 1,
        borderColor: theme.colors.border,
        backgroundColor: theme.colors.card,
      }}
    >
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: theme.spacing.sm }}>
        <Icon color={theme.colors.brand} size={18} />
        <View style={{ flex: 1 }}>
          <Text
            numberOfLines={1}
            style={{
              color: theme.colors.foreground,
              fontFamily: theme.fonts.sansSemibold,
              fontSize: 14,
            }}
          >
            {poi.name}
          </Text>
          <Text
            style={{
              color: theme.colors.mutedForeground,
              fontFamily: theme.fonts.sans,
              fontSize: 12,
            }}
          >
            {formatDistance(poi.distance_m)}
            {detour != null && detour > 0
              ? `  ${t('trip.inRide.detourBadge', {
                  km: (detour / 1000).toFixed(1).replace(/\.0$/, ''),
                })}`
              : ''}
          </Text>
        </View>
      </View>

      {hasOpeningHours ? (
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: theme.spacing.xs }}>
          <Clock color={theme.colors.mutedForeground} size={13} />
          <Text
            numberOfLines={1}
            style={{
              color: theme.colors.mutedForeground,
              fontFamily: theme.fonts.sans,
              fontSize: 12,
            }}
          >
            {poi.opening_hours_today}
          </Text>
        </View>
      ) : (
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: theme.spacing.xs }}>
          <AlertTriangle color={theme.colors.accentBrand} size={13} />
          <Text
            style={{
              color: theme.colors.accentBrand,
              fontFamily: theme.fonts.sans,
              fontSize: 12,
            }}
          >
            {t('trip.inRide.noOpeningHours')}
          </Text>
        </View>
      )}

      {closesAt !== '' ? (
        <Text
          style={{
            color: theme.colors.accentInk,
            fontFamily: theme.fonts.sansMedium,
            fontSize: 12,
          }}
        >
          {t('trip.inRide.closesAt', { time: closesAt })}
        </Text>
      ) : null}

      {poi.phone ? (
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={poi.phone}
          onPress={() => {
            if (isSafePhone(poi.phone ?? '')) Linking.openURL(`tel:${poi.phone}`);
          }}
          style={{ flexDirection: 'row', alignItems: 'center', gap: theme.spacing.xs }}
        >
          <Phone color={theme.colors.brand} size={13} />
          <Text
            style={{ color: theme.colors.brand, fontFamily: theme.fonts.sans, fontSize: 12 }}
          >
            {poi.phone}
          </Text>
        </Pressable>
      ) : null}

      {poi.warning && poi.warning !== 'hours_unverified' && warningText ? (
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: theme.spacing.xs }}>
          <AlertTriangle color={theme.colors.accentBrand} size={13} />
          <Text
            style={{
              color: theme.colors.accentBrand,
              fontFamily: theme.fonts.sans,
              fontSize: 12,
            }}
          >
            {warningText}
          </Text>
        </View>
      ) : null}

      <Pressable
        accessibilityRole="button"
        accessibilityLabel={t('trip.inRide.openInMaps')}
        onPress={() => {
          if (isSafeDeeplink(poi.deeplink)) Linking.openURL(poi.deeplink);
        }}
        style={{
          flexDirection: 'row',
          alignItems: 'center',
          alignSelf: 'flex-start',
          gap: theme.spacing.xs,
          paddingVertical: theme.spacing.sm,
          paddingHorizontal: theme.spacing.md,
          borderRadius: theme.radius.md,
          borderWidth: 1,
          borderColor: theme.colors.border,
          backgroundColor: theme.colors.card,
        }}
      >
        <ExternalLink color={theme.colors.foreground} size={13} />
        <Text
          style={{
            color: theme.colors.foreground,
            fontFamily: theme.fonts.sansMedium,
            fontSize: 13,
          }}
        >
          {t('trip.inRide.openInMaps')}
        </Text>
      </Pressable>
    </View>
  );
}
