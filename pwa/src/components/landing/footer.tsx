"use client";

import Link from "next/link";
import { useTranslations } from "next-intl";
import { Github } from "lucide-react";
import { AttributionFooter } from "@/components/attribution-footer";

/**
 * Landing footer (issue #400).
 *
 * Changes vs the previous inline footer:
 * - The "Créer un voyage" CTA was removed (already exposed in the hero).
 * - Adds "Mentions légales" → /legal and "Confidentialité" → /privacy.
 *   These pages are scheduled for sprint 32 — we use plain anchor tags so
 *   Next.js's link-prefetcher does not warm up routes that don't exist yet.
 */
export function LandingFooter() {
  const t = useTranslations("landing.footer");
  const year = new Date().getFullYear();

  return (
    <footer
      className="py-12 bg-background border-t"
      data-testid="section-footer"
    >
      <div className="max-w-6xl mx-auto px-4 md:px-6">
        <div className="flex flex-col md:flex-row items-start md:items-center justify-between gap-8">
          {/* Brand */}
          <div>
            <p className="font-serif text-xl font-semibold text-brand mb-1">
              Bike Trip Planner
            </p>
            <p className="text-sm text-muted-foreground max-w-xs">
              {t("tagline")}
            </p>
          </div>

          {/* Links */}
          <nav aria-label={t("links")}>
            <ul className="flex flex-wrap gap-x-6 gap-y-2 text-sm text-muted-foreground">
              <li>
                <Link
                  href="/login"
                  className="hover:text-foreground transition-colors"
                >
                  {t("login")}
                </Link>
              </li>
              <li>
                <Link
                  href="/faq"
                  className="hover:text-foreground transition-colors"
                >
                  {t("faq")}
                </Link>
              </li>
              <li>
                <a
                  href="/legal"
                  className="hover:text-foreground transition-colors"
                  data-testid="footer-legal"
                >
                  {t("legal")}
                </a>
              </li>
              <li>
                <a
                  href="/privacy"
                  className="hover:text-foreground transition-colors"
                  data-testid="footer-privacy"
                >
                  {t("privacy")}
                </a>
              </li>
              <li>
                <a
                  href="https://github.com/vincentchalamon/bike-trip-planner"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex items-center gap-1.5 hover:text-foreground transition-colors"
                  data-testid="footer-github"
                >
                  <Github className="h-3.5 w-3.5" />
                  {t("github")}
                </a>
              </li>
            </ul>
          </nav>
        </div>

        <div className="mt-8 pt-6 border-t text-center text-xs text-muted-foreground space-y-1">
          <p>{t("copyright", { year })}</p>
          <AttributionFooter />
        </div>
      </div>
    </footer>
  );
}
