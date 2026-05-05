import { useTranslations } from "next-intl";
import { Monitor, Smartphone, Bot, WifiOff } from "lucide-react";
import type { LucideIcon } from "lucide-react";

type Platform = {
  key: "web" | "pwa" | "android" | "offline";
  icon: LucideIcon;
};

const PLATFORMS: Platform[] = [
  { key: "web", icon: Monitor },
  { key: "pwa", icon: Smartphone },
  { key: "android", icon: Bot },
  { key: "offline", icon: WifiOff },
];

export function LandingPlatforms() {
  const t = useTranslations("landing.availability");

  return (
    <section
      className="py-20 md:py-28 bg-muted/40"
      data-testid="section-availability"
    >
      <div className="max-w-5xl mx-auto px-4 md:px-6">
        <div className="text-center mb-14">
          <h2 className="font-serif text-3xl md:text-5xl font-semibold tracking-tight mb-4">
            {t("title")}
          </h2>
          <p className="text-muted-foreground max-w-xl mx-auto">
            {t("subtitle")}
          </p>
        </div>

        <ul className="grid grid-cols-2 md:grid-cols-4 gap-4 md:gap-5">
          {PLATFORMS.map((platform) => {
            const Icon = platform.icon;
            return (
              <li
                key={platform.key}
                data-testid={`platform-${platform.key}`}
                className="flex flex-col items-center text-center gap-3 rounded-2xl border border-border/60 bg-card p-6 hover:border-brand/30 transition-colors"
              >
                <div className="w-14 h-14 rounded-2xl bg-brand/10 flex items-center justify-center text-brand">
                  <Icon className="h-7 w-7" />
                </div>
                <h3 className="text-sm font-semibold">
                  {t(`${platform.key}Title`)}
                </h3>
                <p className="text-xs text-muted-foreground leading-relaxed">
                  {t(`${platform.key}Description`)}
                </p>
              </li>
            );
          })}
        </ul>
      </div>
    </section>
  );
}
