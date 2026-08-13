import { useFonts } from 'expo-font';
import {
  Fraunces_600SemiBold,
} from '@expo-google-fonts/fraunces';
import {
  InterTight_400Regular,
  InterTight_500Medium,
  InterTight_600SemiBold,
} from '@expo-google-fonts/inter-tight';
import {
  JetBrainsMono_400Regular,
} from '@expo-google-fonts/jetbrains-mono';

// Keys here MUST match the `fonts` token family names (tokens.ts). The web
// pairs Fraunces (serif display), Inter Tight (sans body) and JetBrains Mono.
export const fontMap = {
  Fraunces_600SemiBold,
  InterTight_400Regular,
  InterTight_500Medium,
  InterTight_600SemiBold,
  JetBrainsMono_400Regular,
};

// Returns [loaded, error]; render can proceed on either (system font fallback).
export function useAppFonts(): [boolean, Error | null] {
  return useFonts(fontMap);
}
