import { Pressable, View } from 'react-native';
import { useTheme } from '../../theme/context';

interface SwitchProps {
  value: boolean;
  onValueChange: (value: boolean) => void;
  label: string;
  disabled?: boolean;
}

// A themed track switch (no native Switch dep): a rounded track whose knob slides
// to the checked end; the track fills with the brand accent when on (the theme
// has no dedicated success/green token — brand accent is the closest themed match
// for the mockup's green "on" state).
export function Switch({ value, onValueChange, label, disabled = false }: SwitchProps) {
  const theme = useTheme();
  const W = 46;
  const H = 28;
  const PAD = 3;
  const KNOB = H - 2 * PAD;
  return (
    <Pressable
      accessibilityRole="switch"
      accessibilityLabel={label}
      accessibilityState={{ checked: value, disabled }}
      disabled={disabled}
      onPress={() => onValueChange(!value)}
      hitSlop={8}
      style={{
        width: W,
        height: H,
        borderRadius: H / 2,
        padding: PAD,
        justifyContent: 'center',
        backgroundColor: value ? theme.colors.brandFill : theme.colors.muted,
        borderWidth: value ? 0 : 1,
        borderColor: theme.colors.border,
        opacity: disabled ? 0.5 : 1,
      }}
    >
      <View
        style={{
          width: KNOB,
          height: KNOB,
          borderRadius: KNOB / 2,
          backgroundColor: '#ffffff',
          alignSelf: value ? 'flex-end' : 'flex-start',
          ...theme.shadows.soft,
        }}
      />
    </Pressable>
  );
}
