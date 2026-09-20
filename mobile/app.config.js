// Expo dynamic config. The static config lives in app.json; here we derive the
// Android App Link intent-filter host from EXPO_PUBLIC_API_URL so no build ships
// an App Link permanently wired to a throwaway dev tunnel. Fails closed without
// the var, mirroring src/api/config.ts.
function appLinkHost() {
  const url = process.env.EXPO_PUBLIC_API_URL;
  if (!url) {
    throw new Error('EXPO_PUBLIC_API_URL must be set (see mobile/.env)');
  }
  return new URL(url).host;
}

module.exports = ({ config }) => ({
  ...config,
  android: {
    ...config.android,
    intentFilters: [
      {
        action: 'VIEW',
        autoVerify: true,
        // This list REPLACES app.json's android.intentFilters, so every App Link
        // path must be declared here — a filter left in app.json never reaches
        // the build.
        data: [
          { scheme: 'https', host: appLinkHost(), pathPrefix: '/auth/verify' },
          { scheme: 'https', host: appLinkHost(), pathPrefix: '/account/email-change/verify' },
          { scheme: 'https', host: appLinkHost(), pathPrefix: '/s/' },
        ],
        category: ['BROWSABLE', 'DEFAULT'],
      },
    ],
  },
});
