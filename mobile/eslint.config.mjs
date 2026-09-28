import { defineConfig, globalIgnores } from "eslint/config";
import tseslint from "typescript-eslint";
import reactHooks from "eslint-plugin-react-hooks";

// Mirrors pwa/eslint.config.mjs minus the Next.js presets: the same TypeScript and
// React hooks rule set, with the same overrides and for the same reasons.
export default defineConfig([
    globalIgnores(["node_modules/**", ".expo/**", "android/**", "ios/**", "dist/**"]),
    ...tseslint.configs.recommended,
    reactHooks.configs.flat.recommended,
    {
        // A disable that no longer suppresses anything is a lie about the code.
        linterOptions: { reportUnusedDisableDirectives: "error" },
        rules: {
            // A warning would not fail CI, and a missing dependency is a stale-closure bug.
            "react-hooks/exhaustive-deps": "error",
            "@typescript-eslint/no-unused-vars": [
                "error",
                {
                    argsIgnorePattern: "^_",
                    varsIgnorePattern: "^_",
                    caughtErrorsIgnorePattern: "^_",
                },
            ],
            "@typescript-eslint/no-explicit-any": "error",
            // Metro and Jest configs are CommonJS.
            "@typescript-eslint/no-require-imports": "off",
            "react-hooks/immutability": "off",
            "react-hooks/refs": "off",
            "react-hooks/set-state-in-effect": "off",
        },
    },
    {
        // The suites walk react-test-renderer trees (`root.findAll((n: any) => ...)`)
        // and cast partial fixtures to API shapes; typing each node and fixture would
        // add noise without catching anything. Application code stays strict.
        files: ["**/*.test.{ts,tsx}", "**/__tests__/**"],
        rules: {
            "@typescript-eslint/no-explicit-any": "off",
        },
    },
]);
