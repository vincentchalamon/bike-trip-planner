import { useState } from 'react';
import { Platform, Pressable, StyleSheet, Text, View } from 'react-native';
import DateTimePicker, {
  type DateTimePickerEvent,
} from '@react-native-community/datetimepicker';
import { useTranslation } from 'react-i18next';
import { useTheme } from '../../theme/context';
import { Calendar, X } from './icons';

// ISO 'YYYY-MM-DD' <-> local Date. The field value stays ISO (what the API
// filters / the trip config expect); only the display is localised.
function isoToDate(iso: string): Date {
  const [y, m, d] = iso.split('-').map(Number);
  return y && m && d ? new Date(y, m - 1, d) : new Date();
}
function dateToIso(d: Date): string {
  const p = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}

export interface DateFieldProps {
  value: string; // ISO 'YYYY-MM-DD', or '' when unset
  onChange: (iso: string) => void;
  placeholder: string;
  accessibilityLabel: string;
  label?: string;
  clearLabel?: string;
  disabled?: boolean;
}

// Labelled date field that opens the native calendar picker instead of a raw
// text input. Keeps the value as an ISO string so callers (trips filters, config
// dates) don't change; clearable back to ''.
export function DateField({
  value,
  onChange,
  placeholder,
  accessibilityLabel,
  label,
  clearLabel,
  disabled = false,
}: DateFieldProps) {
  const theme = useTheme();
  const { i18n } = useTranslation();
  const [show, setShow] = useState(false);

  const display = value
    ? new Intl.DateTimeFormat(i18n.language, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
      }).format(isoToDate(value))
    : placeholder;

  const onPick = (event: DateTimePickerEvent, date?: Date) => {
    // iOS renders the spinner inline and fires onChange once per scroll tick
    // (always type 'set', never 'dismissed'); closing on the first tick would
    // hide the wheel before the user reaches their date. Keep it open on iOS —
    // the field tap toggles it shut. Android's calendar dialog closes itself.
    if (Platform.OS !== 'ios') setShow(false);
    if (event.type === 'set' && date) onChange(dateToIso(date));
  };

  return (
    <View>
      {label ? (
        <Text
          style={{
            color: theme.colors.foreground,
            fontFamily: theme.fonts.sansMedium,
            fontSize: 14,
            marginBottom: theme.spacing.xs,
          }}
        >
          {label}
        </Text>
      ) : null}
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={accessibilityLabel}
        accessibilityState={{ disabled }}
        disabled={disabled}
        onPress={() => setShow((s) => (Platform.OS === 'ios' ? !s : true))}
        style={[
          styles.field,
          {
            backgroundColor: theme.colors.surface,
            borderColor: theme.colors.input,
            borderRadius: theme.radius.md,
            paddingHorizontal: theme.spacing.md,
            gap: theme.spacing.sm,
            opacity: disabled ? 0.5 : 1,
          },
        ]}
      >
        <Calendar color={theme.colors.mutedForeground} size={16} />
        <Text
          numberOfLines={1}
          style={{
            flex: 1,
            color: value ? theme.colors.foreground : theme.colors.mutedForeground,
            fontFamily: theme.fonts.sans,
            fontSize: 16,
          }}
        >
          {display}
        </Text>
        {value && !disabled ? (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={clearLabel ?? accessibilityLabel}
            hitSlop={8}
            onPress={() => onChange('')}
          >
            <X color={theme.colors.mutedIcon} size={16} />
          </Pressable>
        ) : null}
      </Pressable>
      {show ? (
        <DateTimePicker
          value={value ? isoToDate(value) : new Date()}
          mode="date"
          display={Platform.OS === 'ios' ? 'spinner' : 'default'}
          onChange={onPick}
        />
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  field: {
    height: 44,
    flexDirection: 'row',
    alignItems: 'center',
    borderWidth: StyleSheet.hairlineWidth,
  },
});
