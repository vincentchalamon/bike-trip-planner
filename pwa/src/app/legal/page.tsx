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
  "publisher",
  "host",
  "contact",
  "intellectualProperty",
] as const;

export default async function LegalNoticePage() {
  const t = await getTranslations("legal");

  const sections: LegalSection[] = SECTION_IDS.map((id) => {
    const key = `sections.${id}.paragraphs`;
    return {
      id,
      title: t(`sections.${id}.title`),
      paragraphs: withContactEmail(asParagraphs(t.raw(key), `legal.${key}`)),
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
        testIdPrefix="legal"
      />
      <LandingFooter />
    </>
  );
}
