import { useTranslations } from "next-intl";
import {
  Mountain,
  CloudSun,
  ShoppingCart,
  BrainCircuit,
  Home,
  Stethoscope,
  ShieldCheck,
  Landmark,
  Download,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";

type CardKey =
  | "terrain"
  | "weather"
  | "supply"
  | "ai"
  | "accommodation"
  | "services"
  | "safety"
  | "poi"
  | "export";

type Tone = "amber" | "ink" | "soft";

type Card = {
  key: CardKey;
  icon: LucideIcon;
  /**
   * Bento layout class — controls col/row span on lg screens. Mobile collapses
   * to a single column for legibility.
   */
  span: string;
  tone: Tone;
};

/**
 * 9-card bento layout (issue #400 §3). Cards vary in size to break the
 * monotony of an even grid. The two "hero" cards (AI, Accommodation) span 2
 * columns to anchor the composition.
 */
const CARDS: Card[] = [
  // Row 1 — terrain wide, weather, supply
  { key: "terrain", icon: Mountain, span: "lg:col-span-2", tone: "amber" },
  { key: "weather", icon: CloudSun, span: "", tone: "soft" },
  { key: "supply", icon: ShoppingCart, span: "", tone: "soft" },
  // Row 2 — AI takes 2 cols, accommodation 2 cols
  { key: "ai", icon: BrainCircuit, span: "lg:col-span-2", tone: "ink" },
  { key: "accommodation", icon: Home, span: "lg:col-span-2", tone: "amber" },
  // Row 3 — services, safety, poi, export
  { key: "services", icon: Stethoscope, span: "", tone: "soft" },
  { key: "safety", icon: ShieldCheck, span: "", tone: "soft" },
  { key: "poi", icon: Landmark, span: "", tone: "soft" },
  { key: "export", icon: Download, span: "", tone: "soft" },
];

function toneClasses(tone: Tone): string {
  switch (tone) {
    case "amber":
      return "bg-brand/10 border-brand/30 hover:border-brand/50";
    case "ink":
      return "bg-foreground text-background border-foreground hover:opacity-95";
    default:
      return "bg-card border-border/60 hover:border-brand/30";
  }
}

function iconClasses(tone: Tone): string {
  switch (tone) {
    case "amber":
      return "bg-brand/15 text-brand";
    case "ink":
      return "bg-background/10 text-background";
    default:
      return "bg-brand/10 text-brand";
  }
}

export function LandingBentoGrid() {
  const t = useTranslations("landing.features");

  return (
    <section
      className="py-20 md:py-28 bg-muted/40"
      data-testid="section-features"
    >
      <div className="max-w-6xl mx-auto px-4 md:px-6">
        <div className="text-center mb-14 md:mb-20">
          <h2 className="font-serif text-3xl md:text-5xl font-semibold tracking-tight mb-4">
            {t("title")}
          </h2>
          <p className="text-muted-foreground max-w-xl mx-auto">
            {t("subtitle")}
          </p>
        </div>

        <div
          className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 auto-rows-fr gap-4 md:gap-5"
          data-testid="bento-grid"
        >
          {CARDS.map((card) => {
            const Icon = card.icon;
            return (
              <article
                key={card.key}
                data-testid={`bento-card-${card.key}`}
                className={`group rounded-2xl border p-6 md:p-7 shadow-sm transition-all duration-200 hover:shadow-md hover:-translate-y-0.5 flex flex-col gap-4 ${toneClasses(
                  card.tone,
                )} ${card.span}`}
              >
                <div
                  className={`w-11 h-11 rounded-xl flex items-center justify-center ${iconClasses(
                    card.tone,
                  )}`}
                >
                  <Icon className="h-5 w-5" />
                </div>
                <div className="flex-1 flex flex-col gap-2">
                  <h3
                    className={`font-serif text-xl font-semibold leading-tight ${
                      card.tone === "ink" ? "text-background" : ""
                    }`}
                  >
                    {t(`${card.key}.title`)}
                  </h3>
                  <p
                    className={`text-sm leading-relaxed ${
                      card.tone === "ink"
                        ? "text-background/75"
                        : "text-muted-foreground"
                    }`}
                  >
                    {t(`${card.key}.description`)}
                  </p>
                </div>
              </article>
            );
          })}
        </div>
      </div>
    </section>
  );
}
