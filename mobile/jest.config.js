const path = require('node:path');

// lucide-react-native v1 serves `icons/*` through its `exports` map, whose
// `react-native` condition — the one jest-expo's resolver picks — points at the
// ESM build. Jest runs CommonJS, so redirect those imports to the package's CJS
// icons, which need no transform. The target is an absolute filesystem path
// because `exports` has no `./dist/*` entry: a bare `lucide-react-native/dist/...`
// specifier is refused by the resolver. Derived from the resolved package rather
// than hardcoded, so it survives hoisting changes.
const lucideCjsIcons = path.join(path.dirname(require.resolve('lucide-react-native')), 'icons');

/** @type {import('jest').Config} */
module.exports = {
  preset: 'jest-expo',
  setupFiles: ['<rootDir>/jest.setup.js'],
  // Map the @btp/core workspace subpaths to their TypeScript sources (outside
  // node_modules) so jest-expo's babel transform picks them up (#1014).
  moduleNameMapper: {
    '^lucide-react-native/icons/(.*)$': path.join(lucideCjsIcons, '$1.js'),
    '^@btp/core/reconciliation$': '<rootDir>/../core/reconciliation.ts',
    '^@btp/core/elevation$': '<rootDir>/../core/elevation.ts',
    '^@btp/core/mercure$': '<rootDir>/../core/mercure.ts',
    '^@btp/core/schema$': '<rootDir>/../core/schema.d.ts',
    '^@btp/core/constants$': '<rootDir>/../core/accommodation-constants.ts',
    '^@btp/core/pacing-presets$': '<rootDir>/../core/pacing-presets.ts',
    '^@btp/core$': '<rootDir>/../core/index.ts',
  },
};
