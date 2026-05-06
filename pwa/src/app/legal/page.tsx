import Link from "next/link";
import { ArrowLeft } from "lucide-react";
import { getTranslations } from "next-intl/server";

export default async function LegalPage() {
  const t = await getTranslations("legal");

  return (
    <main className="max-w-2xl mx-auto px-4 md:px-6 py-12">
      <Link
        href="/"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground mb-8 transition-colors"
        data-testid="legal-back-link"
      >
        <ArrowLeft className="h-4 w-4" aria-hidden="true" />
        {t("backToHome")}
      </Link>

      <div className="mb-10">
        <h1 className="text-3xl font-bold tracking-tight mb-2">{t("title")}</h1>
        <p className="text-muted-foreground">{t("comingSoon")}</p>
      </div>
    </main>
  );
}
