import Link from "next/link";
import { useTranslations } from "next-intl";
import { AttributionFooter } from "@/components/attribution-footer";

// lucide-react 1.x dropped brand icons; inline the GitHub mark.
function GithubIcon({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="currentColor"
      aria-hidden="true"
      className={className}
    >
      <path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23A11.509 11.509 0 0 1 12 5.803c1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.91 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222 0 1.606-.014 2.898-.014 3.293 0 .322.216.694.825.576C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12" />
    </svg>
  );
}

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
                  data-testid="footer-faq-link"
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
                  <GithubIcon className="h-3.5 w-3.5" />
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
