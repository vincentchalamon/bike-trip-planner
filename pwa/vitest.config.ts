import { defineConfig } from "vitest/config";
import path from "path";

export default defineConfig({
  test: {
    environment: "jsdom",
    include: ["src/**/*.test.ts", "src/**/*.test.tsx"],
    globals: true,
    // AI feature flag (recette #649): on for unit tests so the AI component
    // tests render their subjects. The flag is default-off in prod/recette;
    // individual tests can override via `vi.stubEnv` to assert the masked path.
    env: { NEXT_PUBLIC_ENABLE_AI: "true" },
    coverage: {
      provider: "v8",
      reporter: ["text-summary", "lcov"],
      reportsDirectory: "./coverage",
      include: ["src/**/*.{ts,tsx}"],
      exclude: ["src/**/*.test.{ts,tsx}", "src/**/*.d.ts"],
    },
  },
  resolve: {
    alias: {
      "@": path.resolve(__dirname, "./src"),
    },
  },
});
