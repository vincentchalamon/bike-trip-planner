// Expo dynamic config. The static config lives in app.json; here we derive the
// Android App Link intent-filter host from EXPO_PUBLIC_API_URL so no build ships
// an App Link permanently wired to a throwaway dev tunnel. A non-dev build without
// the var fails closed, mirroring src/api/config.ts.
const DEV_FALLBACK = 'https://epidermis-sandlot-headrest.ngrok-free.dev';

function appLinkHost() {
  const url =
    process.env.EXPO_PUBLIC_API_URL ??
    (process.env.NODE_ENV === 'development' ? DEV_FALLBACK : undefined);
  if (!url) {
    throw new Error('EXPO_PUBLIC_API_URL must be set for non-development builds');
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
        data: [
          { scheme: 'https', host: appLinkHost(), pathPrefix: '/auth/verify' },
          { scheme: 'https', host: appLinkHost(), pathPrefix: '/account/email-change/verify' },
        ],
        category: ['BROWSABLE', 'DEFAULT'],
      },
    ],
  },
});
