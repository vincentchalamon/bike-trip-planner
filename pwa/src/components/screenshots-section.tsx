"use client";

import { useState } from "react";
import Image from "next/image";
import { ChevronLeft, ChevronRight } from "lucide-react";
import { useTranslations } from "next-intl";

const SCREENSHOTS = [
  { key: "map", src: "/images/screenshot-map.jpg" },
  { key: "stage", src: "/images/screenshot-stage.jpg" },
  { key: "analysis", src: "/images/screenshot-analysis.jpg" },
] as const;

export function ScreenshotsSection() {
  const t = useTranslations("landing.screenshots");
  const [active, setActive] = useState(0);
  const current = SCREENSHOTS[active] ?? SCREENSHOTS[0];

  const prev = () =>
    setActive((i) => (i === 0 ? SCREENSHOTS.length - 1 : i - 1));
  const next = () =>
    setActive((i) => (i === SCREENSHOTS.length - 1 ? 0 : i + 1));

  return (
    <section
      id="screenshots"
      className="py-20 md:py-28 bg-background"
      data-testid="section-screenshots"
    >
      <div className="max-w-5xl mx-auto px-4 md:px-6">
        <h2 className="text-3xl md:text-4xl font-bold text-center mb-16">
          {t("title")}
        </h2>

        <div className="relative">
          {/* Main screenshot */}
          <div className="relative aspect-video rounded-2xl overflow-hidden border bg-muted shadow-xl">
            <Image
              src={current.src}
              alt={t(current.key)}
              fill
              loading="lazy"
              className="object-cover"
            />
          </div>

          {/* Nav arrows */}
          <button
            type="button"
            onClick={prev}
            aria-label={t("prev")}
            data-testid="screenshot-prev"
            className="absolute left-3 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-background/80 backdrop-blur-sm border shadow-md flex items-center justify-center hover:bg-background transition-colors"
          >
            <ChevronLeft className="h-5 w-5" />
          </button>
          <button
            type="button"
            onClick={next}
            aria-label={t("next")}
            data-testid="screenshot-next"
            className="absolute right-3 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-background/80 backdrop-blur-sm border shadow-md flex items-center justify-center hover:bg-background transition-colors"
          >
            <ChevronRight className="h-5 w-5" />
          </button>
        </div>

        {/* Dot indicators */}
        <div className="flex items-center justify-center gap-3 mt-6">
          {SCREENSHOTS.map((s, i) => (
            <button
              key={s.key}
              type="button"
              onClick={() => setActive(i)}
              aria-label={t(s.key)}
              aria-pressed={i === active}
              className={`h-2 rounded-full transition-all duration-200 ${
                i === active ? "w-8 bg-brand" : "w-2 bg-muted-foreground/30"
              }`}
            />
          ))}
        </div>

        <p className="text-center text-sm text-muted-foreground mt-3">
          {t(current.key)}
        </p>
      </div>
    </section>
  );
}
