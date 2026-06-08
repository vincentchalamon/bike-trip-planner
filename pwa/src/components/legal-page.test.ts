import { describe, expect, it } from "vitest";
import { withContactEmail } from "@/components/legal-page";
import { CONTACT_EMAIL } from "@/lib/constants";

describe("withContactEmail", () => {
  it("substitutes the token with the configured contact address", () => {
    const out = withContactEmail([
      "Contactez-nous à : __CONTACT_EMAIL__.",
      "No token here.",
    ]);

    expect(out[0]).toBe(`Contactez-nous à : ${CONTACT_EMAIL}.`);
    expect(out[0]).not.toContain("__CONTACT_EMAIL__");
    expect(out[1]).toBe("No token here.");
  });

  it("replaces every occurrence in a paragraph", () => {
    const out = withContactEmail(["__CONTACT_EMAIL__ / __CONTACT_EMAIL__"]);

    expect(out[0]).toBe(`${CONTACT_EMAIL} / ${CONTACT_EMAIL}`);
  });
});
