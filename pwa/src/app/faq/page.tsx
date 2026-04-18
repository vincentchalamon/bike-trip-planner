import Link from "next/link";
import { ArrowLeft } from "lucide-react";
import { getTranslations } from "next-intl/server";
import { FaqAccordion, type FaqCategory } from "@/components/faq-accordion";

export default async function FaqPage() {
  const t = await getTranslations("faq");

  const categories: FaqCategory[] = [
    {
      id: "project",
      label: t("categoryProject"),
      items: [
        { question: t("q1"), answer: t("a1") },
        { question: t("q2"), answer: t("a2") },
        { question: t("q3"), answer: t("a3") },
        { question: t("q4"), answer: t("a4") },
      ],
    },
    {
      id: "how-it-works",
      label: t("categoryHowItWorks"),
      items: [
        { question: t("q5"), answer: t("a5") },
        { question: t("q6"), answer: t("a6") },
        { question: t("q7"), answer: t("a7") },
      ],
    },
    {
      id: "access",
      label: t("categoryAccess"),
      items: [
        { question: t("q8"), answer: t("a8") },
        { question: t("q9"), answer: t("a9") },
      ],
    },
  ];

  return (
    <main className="max-w-2xl mx-auto px-4 md:px-6 py-12">
      {/* Back link */}
      <Link
        href="/"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground mb-8 transition-colors"
        data-testid="faq-back-link"
      >
        <ArrowLeft className="h-4 w-4" aria-hidden="true" />
        {t("backToHome")}
      </Link>

      {/* Header */}
      <div className="mb-10">
        <h1 className="text-3xl font-bold tracking-tight mb-2">{t("title")}</h1>
        <p className="text-muted-foreground">{t("description")}</p>
      </div>

      {/* FAQ Categories */}
      <FaqAccordion categories={categories} />

      {/* Footer link back */}
      <div className="mt-12 pt-6 border-t text-center">
        <Link
          href="/"
          className="text-sm text-muted-foreground hover:text-foreground transition-colors"
        >
          {t("backToHome")}
        </Link>
      </div>
    </main>
  );
}
