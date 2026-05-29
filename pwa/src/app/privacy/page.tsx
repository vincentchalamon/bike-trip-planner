import { getTranslations } from "next-intl/server";
import { LegalPageLayout, type LegalSection } from "@/components/legal-page";
import { LandingFooter } from "@/components/landing/footer";

function asParagraphs(raw: unknown, key: string): string[] {
  if (!Array.isArray(raw) || !raw.every((item) => typeof item === "string")) {
    throw new Error(`Translation "${key}" must be an array of strings`);
  }
  return raw;
}

const SECTION_IDS = [
  "controller",
  "basis",
  "purposes",
  "data",
  "retention",
  "rights",
  "processors",
  "analytics",
  "contact",
] as const;

// TODO: the GDPR contact address (contact@bike-trip-planner.app in messages/*.json)
// is a routable placeholder. Replace it with the real mailbox before going to production.
export default async function PrivacyPage() {
  const t = await getTranslations("privacy");

  const sections: LegalSection[] = SECTION_IDS.map((id) => {
    const key = `sections.${id}.paragraphs`;
    return {
      id,
      title: t(`sections.${id}.title`),
      paragraphs: asParagraphs(t.raw(key), `privacy.${key}`),
    };
  });

  return (
    <>
      <LegalPageLayout
        title={t("title")}
        intro={t("intro")}
        lastUpdated={t("lastUpdated")}
        tocLabel={t("tocLabel")}
        backToHome={t("backToHome")}
        sections={sections}
        testIdPrefix="privacy"
      />
      <LandingFooter />
    </>
  );
}
