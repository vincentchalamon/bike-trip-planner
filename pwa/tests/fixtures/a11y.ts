import AxeBuilder from "@axe-core/playwright";
import type { NodeResult, Result } from "axe-core";
import { expect, type Page } from "@playwright/test";

export interface A11yOptions {
  /** WCAG tags to scan. Defaults to WCAG 2.0 level A + AA. */
  tags?: string[];
}

/**
 * Run an axe-core accessibility scan on the current page and assert that no
 * critical or serious WCAG 2.0 A/AA violation is present.
 *
 * Plain helper (no fixture coupling) so it can be imported from any test or
 * fixture chain. Violations with impact `minor`/`moderate` are ignored at this
 * stage; the exhaustive audits run later in Sprint 35.2.
 */
export async function expectNoCriticalA11yViolations(
  page: Page,
  opts: A11yOptions = {},
): Promise<void> {
  const { tags = ["wcag2a", "wcag2aa"] } = opts;
  const results = await new AxeBuilder({ page }).withTags(tags).analyze();
  const violations: Result[] = results.violations;
  const blocking = violations.filter(
    (v) => v.impact === "critical" || v.impact === "serious",
  );

  const summary = blocking
    .map((v: Result) => {
      const targets = v.nodes
        .map((n: NodeResult) => n.target.join(" "))
        .join(", ");
      return `  [${v.impact}] ${v.id}: ${v.help} (${targets})`;
    })
    .join("\n");

  expect(
    blocking,
    blocking.length > 0
      ? `Critical/serious a11y violations:\n${summary}`
      : undefined,
  ).toEqual([]);
}
