import { useTranslations } from "next-intl";
import { FileText, Sparkles, Link as LinkIcon, Activity } from "lucide-react";
import type { LucideIcon } from "lucide-react";

type Source = {
  key: "komoot" | "strava" | "rwgps" | "gpx" | "ai";
  name: string;
  href: string | null;
  external: boolean;
  /**
   * Brand mark — uses an inline glyph (lucide icon) coloured with the brand's
   * canonical hue so we don't have to ship third-party logos. Sprint 25
   * design guidance prefers semantic typography over bitmap logos.
   */
  Icon: LucideIcon;
  color: string;
};

const SOURCES: Source[] = [
  {
    key: "komoot",
    name: "Komoot",
    href: "https://www.komoot.com",
    external: true,
    Icon: LinkIcon,
    color: "#6AA127",
  },
  {
    key: "strava",
    name: "Strava",
    href: "https://www.strava.com",
    external: true,
    Icon: Activity,
    color: "#FC4C02",
  },
  {
    key: "rwgps",
    name: "RideWithGPS",
    href: "https://ridewithgps.com",
    external: true,
    Icon: LinkIcon,
    color: "#E63022",
  },
  {
    key: "gpx",
    name: "GPX",
    href: null,
    external: false,
    Icon: FileText,
    color: "#1a1814",
  },
  {
    key: "ai",
    name: "AI",
    href: null,
    external: false,
    Icon: Sparkles,
    color: "#c2671e",
  },
];

export function LandingSources() {
  const t = useTranslations("landing.sources");

  return (
    <section
      className="py-16 md:py-24 bg-background"
      data-testid="section-sources"
    >
      <div className="max-w-5xl mx-auto px-4 md:px-6 text-center">
        <h2 className="font-serif text-2xl md:text-4xl font-semibold tracking-tight mb-3">
          {t("title")}
        </h2>
        <p className="text-muted-foreground max-w-xl mx-auto mb-12">
          {t("subtitle")}
        </p>

        <ul className="flex flex-wrap items-stretch justify-center gap-4 md:gap-5">
          {SOURCES.map((source) => {
            const Icon = source.Icon;
            const content = (
              <span className="flex h-full flex-col items-center justify-center gap-3 rounded-2xl border border-border/60 bg-card px-6 py-5 min-w-[136px] transition-all hover:border-brand/40 hover:shadow-md">
                <span
                  className="flex h-12 w-12 items-center justify-center rounded-xl"
                  style={{
                    backgroundColor: `${source.color}1A`,
                    color: source.color,
                  }}
                >
                  <Icon className="h-6 w-6" />
                </span>
                <span className="text-sm font-semibold text-foreground">
                  {source.name}
                </span>
                <span className="text-xs text-muted-foreground">
                  {t(`${source.key}Type`)}
                </span>
              </span>
            );

            return (
              <li key={source.key}>
                {source.href ? (
                  <a
                    href={source.href}
                    target={source.external ? "_blank" : undefined}
                    rel={source.external ? "noopener noreferrer" : undefined}
                    aria-label={`${source.name} — ${t(`${source.key}Type`)}`}
                    data-testid={`source-${source.key}`}
                    className="block focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand rounded-2xl"
                  >
                    {content}
                  </a>
                ) : (
                  <div data-testid={`source-${source.key}`}>{content}</div>
                )}
              </li>
            );
          })}
        </ul>
      </div>
    </section>
  );
}
