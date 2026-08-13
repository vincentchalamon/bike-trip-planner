import { defineConfig } from "vitest/config";
import path from "path";

export default defineConfig({
  test: {
    environment: "jsdom",
    include: ["src/**/*.test.ts", "src/**/*.test.tsx"],
    globals: true,
    coverage: {
      provider: "v8",
      reporter: ["text-summary", "lcov"],
      reportsDirectory: "./coverage",
      include: ["src/**/*.{ts,tsx}"],
      exclude: ["src/**/*.test.{ts,tsx}", "src/**/*.d.ts"],
    },
  },
  resolve: {
    // react is nested in pwa/node_modules (19.2.8) while react-dom hoists to the
    // workspace root; without dedupe vite loads two react copies and react-dom
    // throws a version mismatch. Force a single instance resolved from pwa.
    dedupe: ["react", "react-dom"],
    alias: {
      // Subpath aliases must precede the "@btp/core" catch-all so the more
      // specific entry wins (vite matches aliases in order).
      "@btp/core/schema": path.resolve(__dirname, "../core/schema.d.ts"),
      "@btp/core/constants": path.resolve(
        __dirname,
        "../core/accommodation-constants.ts",
      ),
      "@btp/core/mercure": path.resolve(__dirname, "../core/mercure.ts"),
      "@btp/core/reconciliation": path.resolve(
        __dirname,
        "../core/reconciliation.ts",
      ),
      "@btp/core": path.resolve(__dirname, "../core/index.ts"),
      "@": path.resolve(__dirname, "./src"),
    },
  },
});
