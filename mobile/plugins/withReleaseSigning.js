const { withAppBuildGradle } = require('expo/config-plugins');

// The Expo template signs `release` with the public debug keystore. This swaps in
// a release signingConfig read from env vars at Gradle time (so no password ever
// lands in the generated build.gradle). Without ANDROID_KEYSTORE_PATH the build
// keeps the template's debug signing, so local `assembleRelease` still works; the
// CI release job refuses to build without the secrets (mobile-apk.yml).
const MARKER = '// btp-release-signing';

const SIGNING_CONFIG = `signingConfigs {
        ${MARKER}
        if (System.getenv('ANDROID_KEYSTORE_PATH')) {
            release {
                storeFile file(System.getenv('ANDROID_KEYSTORE_PATH'))
                storePassword System.getenv('ANDROID_KEYSTORE_PASSWORD')
                keyAlias System.getenv('ANDROID_KEY_ALIAS')
                keyPassword System.getenv('ANDROID_KEY_PASSWORD')
            }
        }`;

const RELEASE_BUILD_TYPE = /(release \{\s*(?:\/\/[^\n]*\n\s*)*)signingConfig signingConfigs\.debug/;

function applyReleaseSigning(gradle) {
  if (gradle.includes(MARKER)) {
    return gradle;
  }
  if (!/signingConfigs \{/.test(gradle) || !RELEASE_BUILD_TYPE.test(gradle)) {
    // Fail the prebuild rather than silently shipping a debug-signed release.
    throw new Error(
      'withReleaseSigning: android/app/build.gradle no longer matches the expected template',
    );
  }
  return gradle
    .replace(/signingConfigs \{/, SIGNING_CONFIG)
    .replace(
      RELEASE_BUILD_TYPE,
      "$1signingConfig(System.getenv('ANDROID_KEYSTORE_PATH') ? signingConfigs.release : signingConfigs.debug)",
    );
}

const withReleaseSigning = (config) =>
  withAppBuildGradle(config, (cfg) => {
    cfg.modResults.contents = applyReleaseSigning(cfg.modResults.contents);
    return cfg;
  });

module.exports = withReleaseSigning;
module.exports.applyReleaseSigning = applyReleaseSigning;
