import { Redirect } from 'expo-router';
import { useState } from 'react';
import { Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { useAuth } from '../src/auth/store';

export default function Login() {
  const { authenticated, requestLink } = useAuth();
  const [email, setEmail] = useState('');
  const [sent, setSent] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (authenticated) {
    return <Redirect href="/(tabs)" />;
  }

  const submit = async () => {
    setBusy(true);
    setError(null);
    try {
      const ok = await requestLink(email.trim());
      if (ok) {
        setSent(true);
      } else {
        setError("Impossible d'envoyer le lien. Vérifiez l'adresse et réessayez.");
      }
    } catch {
      setError("Impossible d'envoyer le lien. Vérifiez l'adresse et réessayez.");
    } finally {
      setBusy(false);
    }
  };

  return (
    <View style={styles.container}>
      <Text style={styles.title}>Bike Trip Planner</Text>
      {sent ? (
        <Text style={styles.info}>
          Un lien de connexion a été envoyé à {email}. Ouvrez-le sur cet appareil pour vous
          connecter.
        </Text>
      ) : (
        <>
          <Text style={styles.label}>Adresse email</Text>
          <TextInput
            style={styles.input}
            value={email}
            onChangeText={setEmail}
            autoCapitalize="none"
            autoCorrect={false}
            keyboardType="email-address"
            placeholder="vous@exemple.fr"
          />
          {error ? <Text style={styles.error}>{error}</Text> : null}
          <Pressable
            style={[styles.button, (busy || !email) && styles.buttonDisabled]}
            disabled={busy || !email}
            onPress={submit}
          >
            <Text style={styles.buttonText}>{busy ? 'Envoi…' : 'Recevoir un lien'}</Text>
          </Pressable>
        </>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, padding: 24, justifyContent: 'center', gap: 12 },
  title: { fontSize: 24, fontWeight: '700', marginBottom: 24, textAlign: 'center' },
  label: { fontSize: 14, color: '#374151' },
  input: {
    borderWidth: 1,
    borderColor: '#d1d5db',
    borderRadius: 8,
    padding: 12,
    fontSize: 16,
  },
  button: {
    backgroundColor: '#2563eb',
    borderRadius: 8,
    padding: 14,
    alignItems: 'center',
    marginTop: 8,
  },
  buttonDisabled: { opacity: 0.5 },
  buttonText: { color: '#fff', fontWeight: '600', fontSize: 16 },
  info: { fontSize: 16, textAlign: 'center', color: '#374151' },
  error: { color: '#dc2626' },
});
