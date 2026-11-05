const { applyReleaseSigning } = require('./withReleaseSigning');

// Excerpt of the Expo SDK template's android/app/build.gradle (node_modules/expo/template.tgz).
const TEMPLATE = `android {
    signingConfigs {
        debug {
            storeFile file('debug.keystore')
            storePassword 'android'
            keyAlias 'androiddebugkey'
            keyPassword 'android'
        }
    }
    buildTypes {
        debug {
            signingConfig signingConfigs.debug
        }
        release {
            // Caution! In production, you need to generate your own keystore file.
            // see https://reactnative.dev/docs/signed-apk-android.
            signingConfig signingConfigs.debug
            minifyEnabled enableMinifyInReleaseBuilds
        }
    }
}
`;

describe('applyReleaseSigning', () => {
  it('declares a release signingConfig read from env vars', () => {
    const out = applyReleaseSigning(TEMPLATE);

    expect(out).toContain("if (System.getenv('ANDROID_KEYSTORE_PATH')) {");
    expect(out).toContain("storeFile file(System.getenv('ANDROID_KEYSTORE_PATH'))");
    expect(out).toContain("storePassword System.getenv('ANDROID_KEYSTORE_PASSWORD')");
    expect(out).toContain("keyAlias System.getenv('ANDROID_KEY_ALIAS')");
    expect(out).toContain("keyPassword System.getenv('ANDROID_KEY_PASSWORD')");
  });

  it('signs the release build type with it, and only the release one', () => {
    const out = applyReleaseSigning(TEMPLATE);
    const release = out.slice(out.indexOf('buildTypes {'));

    expect(release).toContain(
      "signingConfig(System.getenv('ANDROID_KEYSTORE_PATH') ? signingConfigs.release : signingConfigs.debug)",
    );
    // The debug build type keeps the debug keystore.
    expect(release.match(/^\s*signingConfig signingConfigs\.debug$/gm)).toHaveLength(1);
  });

  it('is idempotent', () => {
    const once = applyReleaseSigning(TEMPLATE);

    expect(applyReleaseSigning(once)).toBe(once);
  });

  it('fails the prebuild when the template no longer matches', () => {
    const drifted = TEMPLATE.replace(
      /release \{[\s\S]*?signingConfig signingConfigs\.debug/,
      'release {\n            signingConfig signingConfigs.somethingElse',
    );

    expect(() => applyReleaseSigning(drifted)).toThrow(/no longer matches/);
  });
});
