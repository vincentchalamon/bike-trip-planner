import { useState } from 'react';
import { View } from 'react-native';
import { Button, Input } from '../ui';
import { useTheme } from '../../theme';

interface DeleteAccountFormProps {
  keyword: string;
  confirmLabel: string;
  cancelLabel: string;
  placeholder?: string;
  inputLabel?: string;
  loading?: boolean;
  onConfirm: () => void;
  onCancel: () => void;
}

// Keyword-confirmation form (maquette 10-account). The danger button stays
// disabled until the typed value matches `keyword` exactly; extracted from the
// screen so the guard is unit-testable without router/auth providers.
export function DeleteAccountForm({
  keyword,
  confirmLabel,
  cancelLabel,
  placeholder,
  inputLabel,
  loading = false,
  onConfirm,
  onCancel,
}: DeleteAccountFormProps) {
  const theme = useTheme();
  const [value, setValue] = useState('');
  const armed = value === keyword;

  return (
    <View style={{ gap: theme.spacing.md }}>
      <Input
        label={inputLabel}
        value={value}
        onChangeText={setValue}
        placeholder={placeholder}
        autoCapitalize="characters"
        autoCorrect={false}
        autoComplete="off"
      />
      <View style={{ flexDirection: 'row', gap: theme.spacing.md }}>
        <Button
          label={cancelLabel}
          variant="outline"
          onPress={onCancel}
          disabled={loading}
          style={{ flex: 1 }}
        />
        <Button
          label={confirmLabel}
          variant="destructive"
          disabled={!armed}
          loading={loading}
          onPress={onConfirm}
          style={{ flex: 1 }}
        />
      </View>
    </View>
  );
}
