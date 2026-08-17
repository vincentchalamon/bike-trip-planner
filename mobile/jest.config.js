/** @type {import('jest').Config} */
module.exports = {
  preset: 'jest-expo',
  // Map the @btp/core workspace subpaths to their TypeScript sources (outside
  // node_modules) so jest-expo's babel transform picks them up (#1014).
  moduleNameMapper: {
    '^@btp/core/reconciliation$': '<rootDir>/../core/reconciliation.ts',
    '^@btp/core/elevation$': '<rootDir>/../core/elevation.ts',
    '^@btp/core/mercure$': '<rootDir>/../core/mercure.ts',
    '^@btp/core/schema$': '<rootDir>/../core/schema.d.ts',
    '^@btp/core/constants$': '<rootDir>/../core/accommodation-constants.ts',
    '^@btp/core$': '<rootDir>/../core/index.ts',
  },
};
