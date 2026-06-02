import AxeBuilder from "@axe-core/playwright";
import type { NodeResult, Result } from "axe-core";
import { expect, type Page } from "@playwright/test";

export interface A11yOptions {
  /** WCAG tags to scan. Defaults to WCAG 2.0 level A + AA. */
  tags?: string[];
}

/**
 * Run an axe-core scan on the current page and return only the critical/serious
 * WCAG violations. Plain helper (no fixture coupling); `minor`/`moderate` are
 * ignored at this stage.
 */
export async function getCriticalA11yViolations(
  page: Page,
  opts: A11yOptions = {},
): Promise<Result[]> {
  const { tags = ["wcag2a", "wcag2aa"] } = opts;
  const results = await new AxeBuilder({ page }).withTags(tags).analyze();
  return results.violations.filter(
    (v) => v.impact === "critical" || v.impact === "serious",
  );
}

/** Format a violation list for assertion / report messages. */
export function formatA11yViolations(violations: Result[]): string {
  return violations
    .map((v: Result) => {
      const targets = v.nodes
        .map((n: NodeResult) => n.target.join(" "))
        .join(", ");
      return `  [${v.impact}] ${v.id}: ${v.help} (${targets})`;
    })
    .join("\n");
}

/**
 * Assert that no critical or serious WCAG 2.0 A/AA violation is present.
 * Used by the exhaustive a11y audit (Sprint 35.2); the Phase-1 smoke uses
 * {@link getCriticalA11yViolations} without gating since the app is not yet
 * audited.
 */
export async function expectNoCriticalA11yViolations(
  page: Page,
  opts: A11yOptions = {},
): Promise<void> {
  const blocking = await getCriticalA11yViolations(page, opts);
  expect(
    blocking,
    blocking.length > 0
      ? `Critical/serious a11y violations:\n${formatA11yViolations(blocking)}`
      : undefined,
  ).toEqual([]);
}
