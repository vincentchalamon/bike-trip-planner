/** @type {import('jest').Config} */
module.exports = {
  preset: 'jest-expo',
  setupFiles: ['<rootDir>/jest.setup.js'],
  // Map the @btp/core workspace subpaths to their TypeScript sources (outside
  // node_modules) so jest-expo's babel transform picks them up (#1014).
  moduleNameMapper: {
    // icons.ts deep-imports each icon from its ESM build for Metro tree-shaking
    // (#1194); Jest runs under CommonJS, so redirect those same paths to the
    // package's CJS build, which needs no transform.
    '^lucide-react-native/dist/esm/icons/(.*)\\.js$': 'lucide-react-native/dist/cjs/icons/$1.js',
    '^@btp/core/reconciliation$': '<rootDir>/../core/reconciliation.ts',
    '^@btp/core/elevation$': '<rootDir>/../core/elevation.ts',
    '^@btp/core/mercure$': '<rootDir>/../core/mercure.ts',
    '^@btp/core/schema$': '<rootDir>/../core/schema.d.ts',
    '^@btp/core/constants$': '<rootDir>/../core/accommodation-constants.ts',
    '^@btp/core/pacing-presets$': '<rootDir>/../core/pacing-presets.ts',
    '^@btp/core$': '<rootDir>/../core/index.ts',
  },
};
