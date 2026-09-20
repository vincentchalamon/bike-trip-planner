// src/api/config.ts resolves these at import time and throws when they are
// missing (no built-in fallback, so no build ever ships a stale tunnel host).
// Any suite importing it transitively needs them set.
process.env.EXPO_PUBLIC_API_URL ??= 'https://api.test.invalid';
process.env.EXPO_PUBLIC_WEB_URL ??= 'https://web.test.invalid';

// Provide safe-area insets in tests (components call useSafeAreaInsets outside a
// SafeAreaProvider); the library ships an official jest mock returning zeros.
jest.mock('react-native-safe-area-context', () =>
  require('react-native-safe-area-context/jest/mock').default,
);
