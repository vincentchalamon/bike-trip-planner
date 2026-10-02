// src/api/config.ts resolves these at import time and throws when they are
// missing (no built-in fallback, so no build ever ships a stale tunnel host).
// Any suite importing it transitively needs them set.
process.env.EXPO_PUBLIC_API_URL ??= 'https://api.test.invalid';
process.env.EXPO_PUBLIC_WEB_URL ??= 'https://web.test.invalid';

// Provide safe-area insets in tests (components call useSafeAreaInsets outside a
// SafeAreaProvider); the library ships an official jest mock returning zeros.
jest.mock(
  'react-native-safe-area-context',
  () => require('react-native-safe-area-context/jest/mock').default,
);

// react-native's index exposes its components through lazy getters, and babel
// compiles `import { Modal } from 'react-native'` into a property read at the use
// site. The first render of a suite therefore requires (and, on a cold transform
// cache, compiles) ~150 react-native modules inside its first test, against the
// 5 s test timeout: under a full parallel run that first test timed out at
// random. Resolve the components the app renders here, where setup has no timeout.
const ReactNative = require('react-native');
[
  'ActivityIndicator',
  'Animated',
  'FlatList',
  'Image',
  'KeyboardAvoidingView',
  'Modal',
  'Pressable',
  'RefreshControl',
  'ScrollView',
  'Text',
  'TextInput',
  'View',
].forEach((name) => ReactNative[name]);
