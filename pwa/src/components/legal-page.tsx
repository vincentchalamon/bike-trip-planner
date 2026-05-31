import Link from "next/link";
import { ArrowLeft } from "lucide-react";

export interface LegalSection {
  id: string;
  title: string;
  /** Paragraphs of body text. Each entry renders as its own <p>. */
  paragraphs: string[];
}

/** Validates a `t.raw()` value as an array of strings, throwing on mismatch. */
export function asParagraphs(raw: unknown, key: string): string[] {
  if (!Array.isArray(raw) || !raw.every((item) => typeof item === "string")) {
    throw new Error(`Translation "${key}" must be an array of strings`);
  }
  return raw;
}

interface LegalPageProps {
  /** Main page heading. */
  title: string;
  /** Short intro paragraph below the heading. */
  intro: string;
  /** Sections rendered as anchored blocks with a sticky table of contents. */
  sections: LegalSection[];
  /** Localized "table of contents" heading. */
  tocLabel: string;
  /** Localized "back to home" link label. */
  backToHome: string;
  /** Localized "last updated" label, e.g. "Dernière mise à jour : 29 mai 2026". */
  lastUpdated: string;
  /** data-testid prefix, e.g. "privacy" or "legal". */
  testIdPrefix: string;
}

/** Splits text on http(s) URLs and email addresses, rendering each as an anchor. */
function linkify(text: string) {
  const pattern = /(https?:\/\/[^\s]+|[\w.+-]+@[\w-]+\.[\w.-]+)/g;
  return text.split(pattern).map((part, index) => {
    if (/^https?:\/\//.test(part)) {
      const href = part.replace(/[.,;:)]+$/, "");
      const trailing = part.slice(href.length);
      return (
        <span key={index}>
          <a
            href={href}
            target="_blank"
            rel="noopener noreferrer"
            className="text-brand underline underline-offset-2 hover:no-underline"
          >
            {href}
          </a>
          {trailing}
        </span>
      );
    }
    if (/^[\w.+-]+@[\w-]+\.[\w.-]+$/.test(part)) {
      const address = part.replace(/[.,;:]+$/, "");
      const trailing = part.slice(address.length);
      return (
        <span key={index}>
          <a
            href={`mailto:${address}`}
            className="text-brand underline underline-offset-2 hover:no-underline"
          >
            {address}
          </a>
          {trailing}
        </span>
      );
    }
    return part;
  });
}

export function LegalPageLayout({
  title,
  intro,
  sections,
  tocLabel,
  backToHome,
  lastUpdated,
  testIdPrefix,
}: LegalPageProps) {
  return (
    <main className="max-w-5xl mx-auto px-4 md:px-6 py-12">
      <Link
        href="/"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground mb-8 transition-colors"
        data-testid={`${testIdPrefix}-back-link`}
      >
        <ArrowLeft className="h-4 w-4" aria-hidden="true" />
        {backToHome}
      </Link>

      <div className="mb-10">
        <h1 className="text-3xl font-bold tracking-tight mb-2">{title}</h1>
        <p className="text-muted-foreground">{intro}</p>
        <p className="text-xs text-muted-foreground mt-2">{lastUpdated}</p>
      </div>

      <div className="grid gap-10 md:grid-cols-[16rem_1fr]">
        {/* Sticky table of contents (desktop) */}
        <nav
          aria-label={tocLabel}
          className="md:sticky md:top-12 md:self-start"
          data-testid={`${testIdPrefix}-toc`}
        >
          <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground mb-3">
            {tocLabel}
          </p>
          <ul className="space-y-2 text-sm border-l border-border">
            {sections.map((section) => (
              <li key={section.id}>
                <a
                  href={`#${section.id}`}
                  className="block -ml-px border-l border-transparent pl-4 text-muted-foreground hover:text-foreground hover:border-brand transition-colors"
                  data-testid={`${testIdPrefix}-toc-${section.id}`}
                >
                  {section.title}
                </a>
              </li>
            ))}
          </ul>
        </nav>

        {/* Content */}
        <div className="space-y-10 min-w-0">
          {sections.map((section) => (
            <section
              key={section.id}
              id={section.id}
              className="scroll-mt-12"
              data-testid={`${testIdPrefix}-section-${section.id}`}
            >
              <h2 className="text-xl font-semibold tracking-tight mb-3">
                {section.title}
              </h2>
              <div className="space-y-3">
                {section.paragraphs.map((paragraph, index) => (
                  <p
                    key={index}
                    className="text-sm text-muted-foreground leading-relaxed"
                  >
                    {linkify(paragraph)}
                  </p>
                ))}
              </div>
            </section>
          ))}
        </div>
      </div>
    </main>
  );
}
