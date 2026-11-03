const { getDefaultConfig } = require('expo/metro-config');

// Expo SDK 58 swaps the global fetch for expo/fetch, which rejects React Native's
// `{ uri, name, type }` FormData file parts: the GPX upload (src/api/trips.ts) fails
// before reaching the network. The client's retry and encoding workarounds
// (src/api/client.ts) were also written against RN's fetch, so keep it. The flag is
// inlined into the bundle at transform time (expo/expo#46982); setting it here, before
// Metro forks its transform workers, covers every build (dev server, export, Gradle,
// EAS) without repeating it in each environment.
process.env.EXPO_PUBLIC_USE_RN_FETCH ??= '1';

module.exports = getDefaultConfig(__dirname);
