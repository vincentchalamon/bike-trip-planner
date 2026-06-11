import { getTranslations } from "next-intl/server";
import {
  LegalPageLayout,
  type LegalSection,
  asParagraphs,
  withContactEmail,
} from "@/components/legal-page";
import { PublicTopBar } from "@/components/public-top-bar";
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

  const sections: LegalSection[] = SECTION_IDS.map((id) => {
    const key = `sections.${id}.paragraphs`;
    return {
      id,
      title: t(`sections.${id}.title`),
      paragraphs: withContactEmail(asParagraphs(t.raw(key), `privacy.${key}`)),
    };
  });

  return (
    <>
      <PublicTopBar />
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
