// react-test-renderer ships no bundled types and has no @types package under
// this toolchain; the smoke test uses it as an untyped renderer.
declare module 'react-test-renderer';

// lucide-react-native ships a single combined .d.ts at the package root; the
// per-icon deep-import paths used by `components/ui/icons.ts` for tree-shaking
// (#1194) have no declaration files of their own.
declare module 'lucide-react-native/dist/esm/icons/*.js' {
  import type { LucideIcon } from 'lucide-react-native';

  const icon: LucideIcon;
  export default icon;
}
