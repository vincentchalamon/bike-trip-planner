import Link from "next/link";
import { getTranslations } from "next-intl/server";
import { Button } from "@/components/ui/button";

interface Copy {
  title: string;
  subtitle: string;
  illustrationAlt: string;
  backHome: string;
}

const FALLBACK_COPY: Copy = {
  title: "Hors-piste",
  subtitle: "Cette page n'existe pas ou a été déplacée.",
  illustrationAlt: "Cycliste perdu en montagne",
  backHome: "Retour à l'accueil",
};

export default async function NotFound() {
  let copy: Copy;
  try {
    const t = await getTranslations("errorPages.notFound");
    copy = {
      title: t("title"),
      subtitle: t("subtitle"),
      illustrationAlt: t("illustrationAlt"),
      backHome: t("backHome"),
    };
  } catch (e) {
    console.error(
      "[not-found] getTranslations failed, falling back to default copy:",
      e,
    );
    copy = FALLBACK_COPY;
  }

  return (
    <main
      className="flex min-h-screen items-center justify-center px-4 py-12 bg-[var(--color-surface)] text-[var(--color-ink)]"
      data-testid="not-found-page"
    >
      <div className="text-center space-y-6 max-w-md">
        {/* Minimalist illustration: lost cyclist on a mountain ridge */}
        <svg
          aria-label={copy.illustrationAlt}
          role="img"
          viewBox="0 0 200 120"
          className="mx-auto h-32 w-auto text-[var(--color-accent-brand)]"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
          data-testid="not-found-illustration"
        >
          {/* Mountain ridge */}
          <path d="M0 90 L40 50 L70 75 L110 30 L150 70 L200 45 L200 120 L0 120 Z" />
          {/* Sun / moon */}
          <circle cx="160" cy="25" r="8" />
          {/* Bike wheels */}
          <circle cx="55" cy="100" r="9" />
          <circle cx="85" cy="100" r="9" />
          {/* Bike frame */}
          <path d="M55 100 L70 80 L85 100 M70 80 L78 80 L85 100 M70 80 L65 70" />
          {/* Question mark above the rider */}
          <path d="M70 60 q2 -8 6 -8 q4 0 4 4 q0 4 -4 6 q-2 1 -2 4" />
          <circle cx="74" cy="70" r="0.5" fill="currentColor" />
        </svg>

        <div className="space-y-3">
          <h1
            className="font-serif text-4xl md:text-5xl font-semibold tracking-tight"
            data-testid="not-found-title"
          >
            {copy.title}
          </h1>
          <p
            className="font-sans text-base md:text-lg text-[var(--color-ink)]/70"
            data-testid="not-found-subtitle"
          >
            {copy.subtitle}
          </p>
        </div>

        <Button asChild size="lg" data-testid="not-found-home-link">
          <Link href="/">{copy.backHome}</Link>
        </Button>
      </div>
    </main>
  );
}
