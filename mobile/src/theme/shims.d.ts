// Ambient stopgap so tsc resolves deps added to package.json in this issue but
// not yet installed in the worktree (node_modules is hard-linked, install is
// the orchestrator's step). Shorthand ambient declarations only apply when no
// real declaration exists, so they yield to the packages' own types once
// installed and can be deleted then.
declare module '@expo-google-fonts/fraunces';
declare module '@expo-google-fonts/inter-tight';
declare module '@expo-google-fonts/jetbrains-mono';
declare module 'lucide-react-native';
// react-test-renderer ships no bundled types and has no @types package under
// this toolchain; the smoke test uses it as an untyped renderer.
declare module 'react-test-renderer';
