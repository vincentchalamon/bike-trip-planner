import { getTranslations } from "next-intl/server";
import { LegalPage, type LegalSection } from "@/components/legal-page";
import { LandingFooter } from "@/components/landing/footer";

const SECTION_IDS = [
  "publisher",
  "host",
  "contact",
  "intellectualProperty",
] as const;

export default async function LegalPage_() {
  const t = await getTranslations("legal");

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
        testIdPrefix="legal"
      />
      <LandingFooter />
    </>
  );
}
