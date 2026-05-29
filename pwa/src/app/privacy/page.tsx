import { getTranslations } from "next-intl/server";
import { LegalPage, type LegalSection } from "@/components/legal-page";
import { LandingFooter } from "@/components/landing/footer";

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

export default async function PrivacyPage() {
  const t = await getTranslations("privacy");

  const sections: LegalSection[] = SECTION_IDS.map((id) => ({
    id,
    title: t(`sections.${id}.title`),
    paragraphs: t.raw(`sections.${id}.paragraphs`) as string[],
  }));

  return (
    <>
      <LegalPage
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
