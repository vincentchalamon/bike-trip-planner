import { Text, View } from 'react-native';
import { Card } from '../ui';
import { useTheme } from '../../theme';

// Shared building blocks for the static account content screens (FAQ, legal
// notice, privacy policy — #1119): a titled text section and a Q/A pair,
// each rendered inside its own Card.

export function ContentSection({ title, body }: { title: string; body: string }) {
  const theme = useTheme();
  return (
    <Card style={{ marginBottom: theme.spacing.md }}>
      <Text
        style={{
          color: theme.colors.foreground,
          fontFamily: theme.fonts.sansSemibold,
          fontSize: 16,
          marginBottom: theme.spacing.sm,
        }}
      >
        {title}
      </Text>
      <Text
        style={{ color: theme.colors.mutedForeground, fontFamily: theme.fonts.sans, fontSize: 14, lineHeight: 21 }}
      >
        {body}
      </Text>
    </Card>
  );
}

export function FaqItem({ question, answer }: { question: string; answer: string }) {
  const theme = useTheme();
  return (
    <Card style={{ marginBottom: theme.spacing.md }}>
      <View style={{ gap: theme.spacing.sm }}>
        <Text
          style={{ color: theme.colors.foreground, fontFamily: theme.fonts.sansSemibold, fontSize: 15 }}
        >
          {question}
        </Text>
        <Text
          style={{ color: theme.colors.mutedForeground, fontFamily: theme.fonts.sans, fontSize: 14, lineHeight: 21 }}
        >
          {answer}
        </Text>
      </View>
    </Card>
  );
}
