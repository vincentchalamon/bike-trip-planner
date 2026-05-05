import { useTranslations } from "next-intl";
import { Star, Quote } from "lucide-react";

const SLOTS = ["1", "2", "3"] as const;

export function LandingTestimonials() {
  const t = useTranslations("landing.testimonials");

  return (
    <section
      className="py-20 md:py-28 bg-background"
      data-testid="section-testimonials"
    >
      <div className="max-w-6xl mx-auto px-4 md:px-6">
        <h2 className="font-serif text-3xl md:text-5xl font-semibold tracking-tight text-center mb-14">
          {t("title")}
        </h2>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-5 md:gap-6">
          {SLOTS.map((slot) => (
            <blockquote
              key={slot}
              data-testid={`testimonial-${slot}`}
              className="relative flex flex-col gap-4 p-6 md:p-7 rounded-2xl border border-border/60 bg-card shadow-sm hover:shadow-md transition-shadow"
            >
              <Quote
                className="absolute top-5 right-5 h-8 w-8 text-brand/15"
                aria-hidden="true"
              />
              <div
                className="flex items-center gap-1"
                aria-label={t("starsLabel")}
              >
                {Array.from({ length: 5 }).map((_, i) => (
                  <Star
                    key={i}
                    className="h-4 w-4 fill-brand text-brand"
                    aria-hidden="true"
                  />
                ))}
              </div>
              <p className="font-serif text-base md:text-lg italic leading-relaxed text-foreground/90 flex-1">
                &ldquo;{t(`${slot}.text`)}&rdquo;
              </p>
              <footer className="pt-3 border-t border-border/40">
                <p className="text-sm font-semibold">{t(`${slot}.author`)}</p>
                <p className="text-xs text-muted-foreground">
                  {t(`${slot}.role`)}
                </p>
              </footer>
            </blockquote>
          ))}
        </div>
      </div>
    </section>
  );
}
