import type { ReactElement } from "react";
import { useTranslations } from "next-intl";
import { Sparkles } from "lucide-react";
import {
  KomootLogo,
  StravaLogo,
  RideWithGpsLogo,
  GpxLogo,
} from "@/components/landing/source-logos";

type Source = {
  key: "komoot" | "strava" | "rwgps" | "gpx" | "ai";
  name: string;
  href: string | null;
  external: boolean;
  /** Brand logo (official SVG mark) or, for generic sources, a lucide glyph. */
  Logo: (props: { className?: string }) => ReactElement;
  /** Tailwind classes for the logo tile (background + foreground/theme). */
  tile: string;
};

const SOURCES: Source[] = [
  {
    key: "komoot",
    name: "Komoot",
    href: "https://www.komoot.com",
    external: true,
    Logo: KomootLogo,
    tile: "bg-[#6AA127]/10",
  },
  {
    key: "strava",
    name: "Strava",
    href: "https://www.strava.com",
    external: true,
    Logo: StravaLogo,
    tile: "bg-[#FC4C02]/10",
  },
  {
    key: "rwgps",
    name: "RideWithGPS",
    href: "https://ridewithgps.com",
    external: true,
    Logo: RideWithGpsLogo,
    tile: "bg-[#E63022]/10",
  },
  {
    key: "gpx",
    name: "GPX",
    href: null,
    external: false,
    // currentColor → readable in both themes (was near-invisible in dark).
    Logo: GpxLogo,
    tile: "bg-muted text-foreground",
  },
  {
    key: "ai",
    name: "AI",
    href: null,
    external: false,
    Logo: (props) => <Sparkles {...props} />,
    tile: "bg-brand/10 text-brand",
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
            const Logo = source.Logo;
            const content = (
              <span className="flex h-full flex-col items-center justify-center gap-3 rounded-2xl border border-border/60 bg-card px-6 py-5 min-w-[136px] transition-all hover:border-brand/40 hover:shadow-md">
                <span
                  className={`flex h-12 w-12 items-center justify-center rounded-xl ${source.tile}`}
                >
                  <Logo className="h-6 w-6" />
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
