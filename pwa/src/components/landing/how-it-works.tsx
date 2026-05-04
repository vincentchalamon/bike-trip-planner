import { useTranslations } from "next-intl";
import { MapPin, Eye, Zap, Wind } from "lucide-react";

const STEPS = [
  { key: "step1", icon: MapPin },
  { key: "step2", icon: Eye },
  { key: "step3", icon: Zap },
  { key: "step4", icon: Wind },
] as const;

export function LandingHowItWorks() {
  const t = useTranslations("landing.howItWorks");

  return (
    <section
      id="how-it-works"
      className="py-20 md:py-28 bg-background"
      data-testid="section-how-it-works"
    >
      <div className="max-w-6xl mx-auto px-4 md:px-6">
        <h2 className="font-serif text-3xl md:text-5xl font-semibold tracking-tight text-center mb-4">
          {t("title")}
        </h2>
        <p className="text-center text-muted-foreground max-w-xl mx-auto mb-16">
          {t("subtitle")}
        </p>

        <ol className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 lg:gap-8">
          {STEPS.map((step, idx) => {
            const Icon = step.icon;
            return (
              <li
                key={step.key}
                className="relative flex flex-col items-start gap-4 p-6 rounded-2xl border border-border/60 bg-card hover:border-brand/40 transition-colors"
              >
                <div className="flex items-center gap-3">
                  <span className="font-serif text-3xl font-semibold text-brand tabular-nums">
                    0{idx + 1}
                  </span>
                  <div className="h-10 w-10 rounded-xl bg-brand/10 border border-brand/20 flex items-center justify-center text-brand">
                    <Icon className="h-5 w-5" />
                  </div>
                </div>
                <div>
                  <h3 className="text-base font-semibold mb-1.5">
                    {t(`${step.key}Title`)}
                  </h3>
                  <p className="text-sm text-muted-foreground leading-relaxed">
                    {t(`${step.key}Description`)}
                  </p>
                </div>
              </li>
            );
          })}
        </ol>
      </div>
    </section>
  );
}
