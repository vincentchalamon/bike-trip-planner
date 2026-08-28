import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

// `--muted-icon` / `--destructive` (web, globals.css) and `mutedIcon` / `destructive`
// (mobile, tokens.ts) must stay identical: web and mobile ship the same design system.
// They drifted once already (#1233 fixed the mobile contrast, the web tokens were missed
// until #1242). This guard fails CI when the light-mode hex values diverge again, instead
// of relying on a hand-maintained "in sync" comment on each side.
//
// Scope is light mode only: both sides express it as a plain hex, whereas web dark mode
// uses oklch() and cannot be compared byte-for-byte against the mobile hex.

// vitest runs with the cwd set to the pwa workspace (locally and in CI via
// `--workspace pwa`), so both files resolve from there.
const css = readFileSync(join(process.cwd(), "src/app/globals.css"), "utf8");
const mobileTokens = readFileSync(
  join(process.cwd(), "..", "mobile/src/theme/tokens.ts"),
  "utf8",
);

// Light values are the first occurrence in each file (light on :root / lightColors;
// dark overrides come later).
function firstHex(source: string, pattern: RegExp): string {
  const hex = source.match(pattern)?.[1];
  if (hex === undefined) {
    throw new Error(`no light-mode hex matched ${pattern}`);
  }
  return hex.toLowerCase();
}

describe("light-mode color-token parity between web and mobile", () => {
  it.each([
    [
      "muted-icon",
      /--muted-icon:\s*(#[0-9a-fA-F]{6})/,
      /\bmutedIcon:\s*'(#[0-9a-fA-F]{6})'/,
    ],
    [
      "destructive",
      /--destructive:\s*(#[0-9a-fA-F]{6})/,
      /\bdestructive:\s*'(#[0-9a-fA-F]{6})'/,
    ],
  ])("keeps %s aligned", (_name, cssPattern, mobilePattern) => {
    expect(firstHex(css, cssPattern)).toBe(
      firstHex(mobileTokens, mobilePattern),
    );
  });
});
