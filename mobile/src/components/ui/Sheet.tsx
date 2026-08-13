import { type ReactNode } from 'react';
import { Modal, Pressable, StyleSheet, Text, View } from 'react-native';
import { useTheme } from '../../theme/context';

interface SheetProps {
  visible: boolean;
  onClose: () => void;
  title?: string;
  children: ReactNode;
}

// Bottom sheet: a slide-up panel over a dimmed, tap-to-dismiss backdrop.
export function Sheet({ visible, onClose, title, children }: SheetProps) {
  const theme = useTheme();
  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose}>
      <Pressable style={styles.backdrop} onPress={onClose} accessibilityLabel="Fermer">
        <Pressable
          // Swallow taps so pressing the panel does not dismiss the sheet.
          onPress={() => {}}
          style={[
            styles.panel,
            {
              backgroundColor: theme.colors.card,
              borderTopLeftRadius: theme.radius['3xl'],
              borderTopRightRadius: theme.radius['3xl'],
              padding: theme.spacing.base,
            },
          ]}
        >
          <View style={[styles.handle, { backgroundColor: theme.colors.border }]} />
          {title ? (
            <Text
              style={{
                color: theme.colors.foreground,
                fontFamily: theme.fonts.serif,
                fontSize: 20,
                marginBottom: theme.spacing.md,
              }}
            >
              {title}
            </Text>
          ) : null}
          {children}
        </Pressable>
      </Pressable>
    </Modal>
  );
}

const styles = StyleSheet.create({
  backdrop: { flex: 1, justifyContent: 'flex-end', backgroundColor: 'rgba(0,0,0,0.4)' },
  panel: { width: '100%' },
  handle: { alignSelf: 'center', width: 36, height: 4, borderRadius: 2, marginBottom: 12 },
});
