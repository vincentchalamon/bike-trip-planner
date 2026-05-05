import Link from "next/link";
import { useTranslations } from "next-intl";
import { Button } from "@/components/ui/button";
import { CtaButton } from "@/components/cta-button";

/**
 * Cinematic hero with a topographic / contour-line SVG background that evokes
 * a fullscreen map without shipping the Leaflet/MapLibre JS bundle on the
 * landing page. Title uses Fraunces (font-serif) per sprint 25 design tokens;
 * primary CTA is amber (brand) per the design brief in issue #400.
 */
export function LandingHero() {
  const t = useTranslations("landing.hero");

  return (
    <section
      className="relative min-h-[100dvh] flex items-center justify-center overflow-hidden bg-surface"
      data-testid="section-hero"
    >
      {/* ── Background: layered topographic map SVG ─────────────────── */}
      <div className="absolute inset-0 z-0" aria-hidden="true">
        {/* Warm paper base */}
        <div className="absolute inset-0 bg-gradient-to-br from-[#f5ecd9] via-[#f0e3c8] to-[#e8d5a8] dark:from-[#1a1814] dark:via-[#241e16] dark:to-[#1a1814]" />

        {/* Contour lines (topographic) */}
        <svg
          className="absolute inset-0 w-full h-full opacity-60 dark:opacity-30"
          viewBox="0 0 1600 900"
          preserveAspectRatio="xMidYMid slice"
          xmlns="http://www.w3.org/2000/svg"
        >
          <defs>
            <pattern
              id="hero-contours"
              x="0"
              y="0"
              width="1600"
              height="900"
              patternUnits="userSpaceOnUse"
            >
              {/* Concentric topographic lines around two peaks */}
              <g
                fill="none"
                stroke="currentColor"
                strokeWidth="1"
                className="text-[#a87838] dark:text-[#c2671e]"
              >
                <ellipse cx="450" cy="380" rx="120" ry="80" opacity="0.45" />
                <ellipse cx="450" cy="380" rx="200" ry="135" opacity="0.40" />
                <ellipse cx="450" cy="380" rx="290" ry="195" opacity="0.30" />
                <ellipse cx="450" cy="380" rx="390" ry="265" opacity="0.20" />
                <ellipse cx="450" cy="380" rx="500" ry="345" opacity="0.12" />

                <ellipse cx="1180" cy="540" rx="100" ry="65" opacity="0.45" />
                <ellipse cx="1180" cy="540" rx="180" ry="120" opacity="0.40" />
                <ellipse cx="1180" cy="540" rx="270" ry="180" opacity="0.30" />
                <ellipse cx="1180" cy="540" rx="370" ry="245" opacity="0.20" />
                <ellipse cx="1180" cy="540" rx="480" ry="320" opacity="0.12" />
              </g>
            </pattern>
          </defs>
          <rect width="100%" height="100%" fill="url(#hero-contours)" />

          {/* Cinematic route line crossing the map */}
          <path
            d="M -50 720 Q 200 600 380 580 T 700 480 Q 880 420 1020 510 T 1300 460 Q 1480 410 1660 360"
            fill="none"
            stroke="var(--brand)"
            strokeWidth="4"
            strokeLinecap="round"
            strokeDasharray="2 10"
            className="opacity-90"
          />
          <path
            d="M -50 720 Q 200 600 380 580 T 700 480 Q 880 420 1020 510 T 1300 460 Q 1480 410 1660 360"
            fill="none"
            stroke="var(--brand)"
            strokeWidth="2"
            strokeLinecap="round"
            className="opacity-50"
          />

          {/* Stage waypoints */}
          {[
            { x: 380, y: 580 },
            { x: 700, y: 480 },
            { x: 1020, y: 510 },
            { x: 1300, y: 460 },
          ].map((p, i) => (
            <g key={i}>
              <circle
                cx={p.x}
                cy={p.y}
                r="14"
                fill="var(--brand)"
                opacity="0.25"
              />
              <circle
                cx={p.x}
                cy={p.y}
                r="6"
                fill="var(--brand)"
                stroke="white"
                strokeWidth="2"
              />
            </g>
          ))}
        </svg>

        {/* Subtle vignette to lift content */}
        <div className="absolute inset-0 bg-gradient-to-b from-transparent via-transparent to-surface/70" />
      </div>

      {/* ── Foreground content ───────────────────────────────────────── */}
      <div className="relative z-10 text-center px-4 max-w-3xl mx-auto py-24">
        <span className="inline-block mb-6 px-4 py-1.5 text-sm font-medium rounded-full bg-brand/15 text-brand border border-brand/30 backdrop-blur-sm">
          {t("badge")}
        </span>

        <h1 className="font-serif text-5xl sm:text-6xl md:text-7xl lg:text-8xl font-semibold leading-[1.05] tracking-tight mb-6 text-foreground">
          {t("title")}
        </h1>

        <p className="text-lg md:text-xl text-muted-foreground mb-10 leading-relaxed max-w-2xl mx-auto">
          {t("subtitle")}
        </p>

        <div className="flex flex-col sm:flex-row items-center justify-center gap-4">
          <CtaButton label={t("ctaPrimary")} size="lg" />
          <Button variant="outline" size="lg" asChild>
            <Link href="#how-it-works" data-testid="cta-demo">
              {t("ctaDemo")}
            </Link>
          </Button>
        </div>
      </div>

      {/* Scroll indicator */}
      <div
        className="absolute bottom-8 left-1/2 -translate-x-1/2 z-10 animate-bounce"
        aria-hidden="true"
      >
        <div className="w-6 h-10 rounded-full border-2 border-foreground/30 flex items-start justify-center pt-2">
          <div className="w-1.5 h-2.5 bg-foreground/50 rounded-full" />
        </div>
      </div>
    </section>
  );
}
