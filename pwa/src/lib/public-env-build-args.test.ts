import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

// Next.js only inlines a NEXT_PUBLIC_* value that is present in the environment
// of the process running `next build`. A Docker `ARG` alone is not: it has to be
// forwarded to the build command too. Forgetting that export breaks nothing
// loudly — the variable just silently falls back to its code default in every
// deployed bundle (NEXT_PUBLIC_CONTACT_EMAIL did, PR #1295).
const DOCKERFILE = join(import.meta.dirname, "../../../.docker/pwa/Dockerfile");

describe("pwa Dockerfile", () => {
  it("forwards every NEXT_PUBLIC_* build arg into the next build environment", () => {
    const dockerfile = readFileSync(DOCKERFILE, "utf8");
    const declared = [
      ...dockerfile.matchAll(/^ARG (NEXT_PUBLIC_[A-Z0-9_]+)/gm),
    ].map(([, name]) => name);
    const buildStep = dockerfile.slice(dockerfile.indexOf("RUN --mount="));

    expect(declared.length).toBeGreaterThan(0);
    for (const name of declared) {
      expect(
        buildStep,
        `${name} is declared as ARG but never exported`,
      ).toContain(`${name}="\${${name}}"`);
    }
  });
});
