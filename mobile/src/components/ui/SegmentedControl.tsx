import { Pressable, StyleSheet, Text, View } from 'react-native';
import { useTheme } from '../../theme/context';

export interface Segment<T extends string> {
  value: T;
  label: string;
}

interface SegmentedControlProps<T extends string> {
  segments: readonly Segment<T>[];
  value: T;
  onChange: (value: T) => void;
}

// iOS-style segmented switch: the active segment sits on the surface, the track
// uses the muted colour.
export function SegmentedControl<T extends string>({
  segments,
  value,
  onChange,
}: SegmentedControlProps<T>) {
  const theme = useTheme();
  return (
    <View
      style={[
        styles.track,
        { backgroundColor: theme.colors.muted, borderRadius: theme.radius.md, padding: 3 },
      ]}
    >
      {segments.map((seg) => {
        const active = seg.value === value;
        return (
          <Pressable
            key={seg.value}
            accessibilityRole="button"
            accessibilityState={{ selected: active }}
            onPress={() => onChange(seg.value)}
            style={[
              styles.segment,
              {
                borderRadius: theme.radius.sm,
                backgroundColor: active ? theme.colors.surface : 'transparent',
              },
              active && theme.shadows.soft,
            ]}
          >
            <Text
              style={{
                color: active ? theme.colors.foreground : theme.colors.mutedForeground,
                fontFamily: active ? theme.fonts.sansSemibold : theme.fonts.sansMedium,
                fontSize: 14,
              }}
            >
              {seg.label}
            </Text>
          </Pressable>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  track: { flexDirection: 'row' },
  segment: { flex: 1, alignItems: 'center', justifyContent: 'center', paddingVertical: 8 },
});
