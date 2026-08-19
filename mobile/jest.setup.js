// Provide safe-area insets in tests (components call useSafeAreaInsets outside a
// SafeAreaProvider); the library ships an official jest mock returning zeros.
jest.mock('react-native-safe-area-context', () =>
  require('react-native-safe-area-context/jest/mock').default,
);
