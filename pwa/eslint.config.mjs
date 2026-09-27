import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";

const eslintConfig = defineConfig([
    ...nextVitals,
    ...nextTs,
    // Override default ignores of eslint-config-next.
    globalIgnores([
        "node_modules/**",
        // Default ignores of eslint-config-next:
        ".next/**",
        "out/**",
        "build/**",
        "next-env.d.ts",
    ]),
    {
        rules: {
            "@typescript-eslint/no-empty-object-type": "off",
            // Dead code, caught at the gate rather than by a reviewer (#1328). It was
            // "off" from the PWA's scaffold commit, in a batch of rules silenced wholesale
            // to get started — the same batch `no-explicit-any` has since been promoted out
            // of. Nothing recorded a reason to keep it off.
            //
            // The `^_` patterns are not a loophole: this codebase already writes `_stage`
            // for a parameter a step signature must declare and does not use, and the rule's
            // default config does not honour that convention. Owning unused *parameters* too
            // is why this sits here rather than in `noUnusedLocals`, which cannot see them.
            "@typescript-eslint/no-unused-vars": [
                "error",
                {
                    argsIgnorePattern: "^_",
                    varsIgnorePattern: "^_",
                    caughtErrorsIgnorePattern: "^_",
                },
            ],
            "@typescript-eslint/no-explicit-any": "error",
            "@typescript-eslint/no-require-imports": "off",
            "react-hooks/immutability": "off",
            "react-hooks/refs": "off",
            "react-hooks/set-state-in-effect": "off",
            // The structured logger (src/lib/logger.ts) is the single sanctioned
            // console caller; everything else must route through it so production
            // logging stays consistent and Sentry-ready.
            "no-console": "error",
        },
    },
    {
        files: ["src/lib/logger.ts"],
        rules: {
            "no-console": "off",
        },
    },
    {
        files: ["tests/**/*.ts"],
        rules: {
            "react-hooks/rules-of-hooks": "off",
        },
    },
]);

export default eslintConfig;
