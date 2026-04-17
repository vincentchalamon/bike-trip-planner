"use client";

import { useState } from "react";
import Image from "next/image";
import Link from "next/link";
import { useTranslations } from "next-intl";
import {
  MapPin,
  Eye,
  Zap,
  Wind,
  ShieldCheck,
  ShoppingCart,
  BrainCircuit,
  Home,
  CloudSun,
  Stethoscope,
  Monitor,
  Smartphone,
  WifiOff,
  Star,
  Github,
  ChevronLeft,
  ChevronRight,
  ArrowRight,
  Wifi,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { useAuthStore } from "@/store/auth-store";

// ─── CTA button (primary action) ─────────────────────────────────────────────

function CtaButton({
  label,
  size = "default",
  className = "",
}: {
  label: string;
  size?: "default" | "lg" | "sm" | "icon";
  className?: string;
}) {
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);
  const href = isAuthenticated ? "/trips/new" : "/login";

  return (
    <Button
      asChild
      size={size}
      className={`bg-brand hover:bg-brand-hover text-white font-semibold ${className}`}
    >
      <Link href={href} data-testid="cta-create-itinerary">
        {label}
      </Link>
    </Button>
  );
}

// ─── Section 1: Hero ─────────────────────────────────────────────────────────

function HeroSection() {
  const t = useTranslations("landing.hero");

  return (
    <section
      className="relative min-h-screen flex items-center justify-center overflow-hidden"
      data-testid="section-hero"
    >
      {/* Background image with overlay */}
      <div className="absolute inset-0 z-0">
        <Image
          src="/images/hero.jpg"
          alt=""
          fill
          priority
          className="object-cover"
          aria-hidden="true"
        />
        <div className="absolute inset-0 bg-black/55" />
      </div>

      {/* Content */}
      <div className="relative z-10 text-center text-white px-4 max-w-3xl mx-auto">
        {/* Badge */}
        <span className="inline-block mb-6 px-4 py-1.5 text-sm font-medium rounded-full bg-brand/80 text-white border border-brand/60 backdrop-blur-sm">
          {t("badge")}
        </span>

        <h1 className="text-4xl md:text-6xl font-bold leading-tight mb-6 drop-shadow-lg">
          {t("title")}
        </h1>

        <p className="text-lg md:text-xl text-white/85 mb-10 leading-relaxed max-w-2xl mx-auto drop-shadow">
          {t("subtitle")}
        </p>

        <div className="flex flex-col sm:flex-row items-center justify-center gap-4">
          <CtaButton label={t("ctaPrimary")} size="lg" />
          <Button
            variant="outline"
            size="lg"
            className="border-white/40 text-white bg-white/10 hover:bg-white/20 backdrop-blur-sm"
            asChild
          >
            <Link href="#screenshots" data-testid="cta-demo">
              {t("ctaDemo")}
            </Link>
          </Button>
        </div>
      </div>

      {/* Scroll indicator */}
      <div className="absolute bottom-8 left-1/2 -translate-x-1/2 z-10 animate-bounce">
        <div className="w-6 h-10 rounded-full border-2 border-white/40 flex items-start justify-center pt-2">
          <div className="w-1.5 h-2.5 bg-white/60 rounded-full" />
        </div>
      </div>
    </section>
  );
}

// ─── Section 2: How it works ──────────────────────────────────────────────────

function HowItWorksSection() {
  const t = useTranslations("landing.howItWorks");

  return (
    <section
      className="py-20 md:py-28 bg-background"
      data-testid="section-how-it-works"
    >
      <div className="max-w-5xl mx-auto px-4 md:px-6">
        <h2 className="text-3xl md:text-4xl font-bold text-center mb-16">
          {t("title")}
        </h2>

        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-8 md:gap-4">
          {/* Step 1 */}
          <div className="flex flex-col items-center text-center">
            <div className="relative mb-5">
              <div className="w-16 h-16 rounded-full bg-brand/10 border-2 border-brand/30 flex items-center justify-center text-brand">
                <MapPin className="h-7 w-7" />
              </div>
              <span className="absolute -top-1.5 -right-1.5 w-6 h-6 rounded-full bg-brand text-white text-xs font-bold flex items-center justify-center">
                1
              </span>
            </div>
            <h3 className="text-base font-semibold mb-2">{t("step1Title")}</h3>
            <p className="text-sm text-muted-foreground leading-relaxed">
              {t("step1Description")}
            </p>
          </div>

          {/* Step 2 */}
          <div className="flex flex-col items-center text-center">
            <div className="relative mb-5">
              <div className="w-16 h-16 rounded-full bg-brand/10 border-2 border-brand/30 flex items-center justify-center text-brand">
                <Eye className="h-7 w-7" />
              </div>
              <span className="absolute -top-1.5 -right-1.5 w-6 h-6 rounded-full bg-brand text-white text-xs font-bold flex items-center justify-center">
                2
              </span>
            </div>
            <h3 className="text-base font-semibold mb-2">{t("step2Title")}</h3>
            <p className="text-sm text-muted-foreground leading-relaxed">
              {t("step2Description")}
            </p>
          </div>

          {/* Step 3 */}
          <div className="flex flex-col items-center text-center">
            <div className="relative mb-5">
              <div className="w-16 h-16 rounded-full bg-brand/10 border-2 border-brand/30 flex items-center justify-center text-brand">
                <Zap className="h-7 w-7" />
              </div>
              <span className="absolute -top-1.5 -right-1.5 w-6 h-6 rounded-full bg-brand text-white text-xs font-bold flex items-center justify-center">
                3
              </span>
            </div>
            <h3 className="text-base font-semibold mb-2">{t("step3Title")}</h3>
            <p className="text-sm text-muted-foreground leading-relaxed">
              {t("step3Description")}
            </p>
          </div>

          {/* Step 4 */}
          <div className="flex flex-col items-center text-center">
            <div className="relative mb-5">
              <div className="w-16 h-16 rounded-full bg-brand/10 border-2 border-brand/30 flex items-center justify-center text-brand">
                <Wind className="h-7 w-7" />
              </div>
              <span className="absolute -top-1.5 -right-1.5 w-6 h-6 rounded-full bg-brand text-white text-xs font-bold flex items-center justify-center">
                4
              </span>
            </div>
            <h3 className="text-base font-semibold mb-2">{t("step4Title")}</h3>
            <p className="text-sm text-muted-foreground leading-relaxed">
              {t("step4Description")}
            </p>
          </div>
        </div>

        {/* Step connectors (desktop only — decorative) */}
        <div
          className="hidden md:flex justify-between items-center px-10 -mt-28 mb-16 pointer-events-none"
          aria-hidden="true"
        >
          <div className="flex-1 flex items-center justify-center">
            <ArrowRight className="h-5 w-5 text-brand/30" />
          </div>
          <div className="flex-1 flex items-center justify-center">
            <ArrowRight className="h-5 w-5 text-brand/30" />
          </div>
          <div className="flex-1 flex items-center justify-center">
            <ArrowRight className="h-5 w-5 text-brand/30" />
          </div>
        </div>
      </div>
    </section>
  );
}

// ─── Section 3: Features (bento-grid) ────────────────────────────────────────

function FeaturesSection() {
  const t = useTranslations("landing.features");

  return (
    <section
      className="py-20 md:py-28 bg-muted/40"
      data-testid="section-features"
    >
      <div className="max-w-5xl mx-auto px-4 md:px-6">
        <h2 className="text-3xl md:text-4xl font-bold text-center mb-16">
          {t("title")}
        </h2>

        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
          {/* Terrain & Sécurité */}
          <div className="rounded-2xl border bg-card p-6 shadow-sm hover:shadow-md transition-shadow">
            <div className="w-10 h-10 rounded-xl bg-brand/10 flex items-center justify-center mb-4">
              <ShieldCheck className="h-6 w-6 text-brand" />
            </div>
            <h3 className="text-base font-semibold mb-2">
              {t("terrain.title")}
            </h3>
            <p className="text-sm text-muted-foreground leading-relaxed">
              {t("terrain.description")}
            </p>
          </div>

          {/* Ravitaillement */}
          <div className="rounded-2xl border bg-card p-6 shadow-sm hover:shadow-md transition-shadow">
            <div className="w-10 h-10 rounded-xl bg-brand/10 flex items-center justify-center mb-4">
              <ShoppingCart className="h-6 w-6 text-brand" />
            </div>
            <h3 className="text-base font-semibold mb-2">
              {t("supply.title")}
            </h3>
            <p className="text-sm text-muted-foreground leading-relaxed">
              {t("supply.description")}
            </p>
          </div>

          {/* AI — span 2 columns */}
          <div className="rounded-2xl border bg-card p-6 shadow-sm hover:shadow-md transition-shadow md:col-span-2">
            <div className="w-10 h-10 rounded-xl bg-brand/10 flex items-center justify-center mb-4">
              <BrainCircuit className="h-6 w-6 text-brand" />
            </div>
            <h3 className="text-base font-semibold mb-2">{t("ai.title")}</h3>
            <p className="text-sm text-muted-foreground leading-relaxed">
              {t("ai.description")}
            </p>
          </div>

          {/* Hébergements */}
          <div className="rounded-2xl border bg-card p-6 shadow-sm hover:shadow-md transition-shadow">
            <div className="w-10 h-10 rounded-xl bg-brand/10 flex items-center justify-center mb-4">
              <Home className="h-6 w-6 text-brand" />
            </div>
            <h3 className="text-base font-semibold mb-2">
              {t("accommodation.title")}
            </h3>
            <p className="text-sm text-muted-foreground leading-relaxed">
              {t("accommodation.description")}
            </p>
          </div>

          {/* Météo */}
          <div className="rounded-2xl border bg-card p-6 shadow-sm hover:shadow-md transition-shadow">
            <div className="w-10 h-10 rounded-xl bg-brand/10 flex items-center justify-center mb-4">
              <CloudSun className="h-6 w-6 text-brand" />
            </div>
            <h3 className="text-base font-semibold mb-2">
              {t("weather.title")}
            </h3>
            <p className="text-sm text-muted-foreground leading-relaxed">
              {t("weather.description")}
            </p>
          </div>

          {/* Services */}
          <div className="rounded-2xl border bg-card p-6 shadow-sm hover:shadow-md transition-shadow">
            <div className="w-10 h-10 rounded-xl bg-brand/10 flex items-center justify-center mb-4">
              <Stethoscope className="h-6 w-6 text-brand" />
            </div>
            <h3 className="text-base font-semibold mb-2">
              {t("services.title")}
            </h3>
            <p className="text-sm text-muted-foreground leading-relaxed">
              {t("services.description")}
            </p>
          </div>
        </div>
      </div>
    </section>
  );
}

// ─── Section 4: Supported sources ────────────────────────────────────────────

const SOURCES = [
  { name: "Komoot", logo: "/images/logos/komoot.svg" },
  { name: "RideWithGPS", logo: "/images/logos/ridewithgps.svg" },
  { name: "Strava", logo: "/images/logos/strava.svg" },
  { name: "GPX", logo: "/images/logos/gpx.svg" },
  { name: "AI", logo: "/images/logos/ai.svg" },
];

function SourcesSection() {
  const t = useTranslations("landing.sources");

  return (
    <section
      className="py-16 md:py-20 bg-background"
      data-testid="section-sources"
    >
      <div className="max-w-4xl mx-auto px-4 md:px-6 text-center">
        <h2 className="text-2xl md:text-3xl font-bold mb-12">{t("title")}</h2>

        <div className="flex flex-wrap items-center justify-center gap-8 md:gap-12">
          {SOURCES.map((source) => (
            <div
              key={source.name}
              className="flex flex-col items-center gap-2 opacity-70 hover:opacity-100 transition-opacity"
            >
              <div className="w-16 h-16 rounded-xl bg-muted flex items-center justify-center overflow-hidden">
                <Image
                  src={source.logo}
                  alt={source.name}
                  width={48}
                  height={48}
                  loading="lazy"
                  className="object-contain"
                />
              </div>
              <span className="text-sm font-medium text-muted-foreground">
                {source.name}
              </span>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

// ─── Section 5: Availability ──────────────────────────────────────────────────

function AvailabilitySection() {
  const t = useTranslations("landing.availability");

  return (
    <section
      className="py-20 md:py-28 bg-muted/40"
      data-testid="section-availability"
    >
      <div className="max-w-5xl mx-auto px-4 md:px-6">
        <h2 className="text-3xl md:text-4xl font-bold text-center mb-16">
          {t("title")}
        </h2>

        <div className="grid grid-cols-2 md:grid-cols-4 gap-6 md:gap-8">
          <div className="flex flex-col items-center text-center gap-3">
            <div className="w-14 h-14 rounded-full bg-brand/10 flex items-center justify-center">
              <Monitor className="h-8 w-8 text-brand" />
            </div>
            <h3 className="text-sm font-semibold">{t("responsive")}</h3>
            <p className="text-xs text-muted-foreground leading-relaxed">
              {t("responsiveDescription")}
            </p>
          </div>

          <div className="flex flex-col items-center text-center gap-3">
            <div className="w-14 h-14 rounded-full bg-brand/10 flex items-center justify-center">
              <Smartphone className="h-8 w-8 text-brand" />
            </div>
            <h3 className="text-sm font-semibold">{t("pwa")}</h3>
            <p className="text-xs text-muted-foreground leading-relaxed">
              {t("pwaDescription")}
            </p>
          </div>

          <div className="flex flex-col items-center text-center gap-3">
            <div className="w-14 h-14 rounded-full bg-brand/10 flex items-center justify-center">
              <Smartphone className="h-8 w-8 text-brand" />
            </div>
            <h3 className="text-sm font-semibold">{t("android")}</h3>
            <p className="text-xs text-muted-foreground leading-relaxed">
              {t("androidDescription")}
            </p>
          </div>

          <div className="flex flex-col items-center text-center gap-3">
            <div className="w-14 h-14 rounded-full bg-brand/10 flex items-center justify-center">
              <WifiOff className="h-8 w-8 text-brand" />
            </div>
            <h3 className="text-sm font-semibold">{t("offline")}</h3>
            <p className="text-xs text-muted-foreground leading-relaxed">
              {t("offlineDescription")}
            </p>
          </div>
        </div>

        {/* PWA visual banner */}
        <div className="mt-14 flex justify-center">
          <div className="relative w-full max-w-lg h-48 rounded-2xl overflow-hidden border bg-card shadow-lg">
            <Image
              src="/images/screenshot-mobile.jpg"
              alt=""
              fill
              loading="lazy"
              className="object-cover"
              aria-hidden="true"
            />
            <div className="absolute inset-0 bg-black/30" />
            <div className="absolute inset-0 flex items-center justify-center">
              <div className="flex items-center gap-2 bg-background/80 backdrop-blur-sm rounded-full px-5 py-2.5 text-sm font-medium">
                <Wifi className="h-4 w-4 text-brand" />
                <span>{t("pwa")}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}

// ─── Section 6: Screenshots slider ───────────────────────────────────────────

const SCREENSHOTS = [
  { key: "map", src: "/images/screenshot-map.jpg" },
  { key: "stage", src: "/images/screenshot-stage.jpg" },
  { key: "analysis", src: "/images/screenshot-analysis.jpg" },
] as const;

function ScreenshotsSection() {
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
            className="absolute left-3 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-background/80 backdrop-blur-sm border shadow-md flex items-center justify-center hover:bg-background transition-colors"
          >
            <ChevronLeft className="h-5 w-5" />
          </button>
          <button
            type="button"
            onClick={next}
            aria-label={t("next")}
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
              aria-current={i === active ? "true" : undefined}
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

// ─── Section 7: Testimonials ──────────────────────────────────────────────────

function TestimonialsSection() {
  const t = useTranslations("landing.testimonials");

  return (
    <section
      className="py-20 md:py-28 bg-muted/40"
      data-testid="section-testimonials"
    >
      <div className="max-w-5xl mx-auto px-4 md:px-6">
        <h2 className="text-3xl md:text-4xl font-bold text-center mb-16">
          {t("title")}
        </h2>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          {/* Testimonial 1 */}
          <blockquote className="rounded-2xl border bg-card p-6 shadow-sm flex flex-col gap-4">
            <div className="flex gap-1" aria-label={t("starsLabel")}>
              {Array.from({ length: 5 }).map((_, i) => (
                <Star
                  key={i}
                  className="h-4 w-4 fill-amber-400 text-amber-400"
                  aria-hidden="true"
                />
              ))}
            </div>
            <p className="text-sm leading-relaxed text-muted-foreground flex-1">
              &ldquo;{t("1.text")}&rdquo;
            </p>
            <footer>
              <p className="text-sm font-semibold">{t("1.author")}</p>
              <p className="text-xs text-muted-foreground">{t("1.role")}</p>
            </footer>
          </blockquote>

          {/* Testimonial 2 */}
          <blockquote className="rounded-2xl border bg-card p-6 shadow-sm flex flex-col gap-4">
            <div className="flex gap-1" aria-label={t("starsLabel")}>
              {Array.from({ length: 5 }).map((_, i) => (
                <Star
                  key={i}
                  className="h-4 w-4 fill-amber-400 text-amber-400"
                  aria-hidden="true"
                />
              ))}
            </div>
            <p className="text-sm leading-relaxed text-muted-foreground flex-1">
              &ldquo;{t("2.text")}&rdquo;
            </p>
            <footer>
              <p className="text-sm font-semibold">{t("2.author")}</p>
              <p className="text-xs text-muted-foreground">{t("2.role")}</p>
            </footer>
          </blockquote>

          {/* Testimonial 3 */}
          <blockquote className="rounded-2xl border bg-card p-6 shadow-sm flex flex-col gap-4">
            <div className="flex gap-1" aria-label={t("starsLabel")}>
              {Array.from({ length: 5 }).map((_, i) => (
                <Star
                  key={i}
                  className="h-4 w-4 fill-amber-400 text-amber-400"
                  aria-hidden="true"
                />
              ))}
            </div>
            <p className="text-sm leading-relaxed text-muted-foreground flex-1">
              &ldquo;{t("3.text")}&rdquo;
            </p>
            <footer>
              <p className="text-sm font-semibold">{t("3.author")}</p>
              <p className="text-xs text-muted-foreground">{t("3.role")}</p>
            </footer>
          </blockquote>
        </div>
      </div>
    </section>
  );
}

// ─── Section 8: Early access CTA ─────────────────────────────────────────────

function EarlyAccessSection() {
  const t = useTranslations("landing.earlyAccess");
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);
  const [email, setEmail] = useState("");
  const [submitted, setSubmitted] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!email.trim()) return;
    setSubmitting(true);
    try {
      // Waiting list submission — graceful no-op for now (backend endpoint TBD)
      await new Promise<void>((resolve) => setTimeout(resolve, 600));
      setSubmitted(true);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <section
      className="py-24 md:py-32 bg-foreground text-background"
      data-testid="section-early-access"
    >
      <div className="max-w-2xl mx-auto px-4 md:px-6 text-center">
        <h2 className="text-3xl md:text-4xl font-bold mb-4">{t("title")}</h2>
        <p className="text-lg opacity-75 mb-10">{t("description")}</p>

        <CtaButton label={t("ctaPrimary")} size="lg" className="mb-10" />

        {/* Waiting list form — only for unauthenticated visitors */}
        {!isAuthenticated && (
          <div className="border border-background/20 rounded-2xl p-6 bg-background/5 backdrop-blur-sm">
            <p className="text-sm font-semibold opacity-80 mb-4">
              {t("waitingListTitle")}
            </p>

            {submitted ? (
              <p
                className="text-sm text-green-300"
                role="status"
                data-testid="waiting-list-success"
              >
                {t("successMessage")}
              </p>
            ) : (
              <form
                onSubmit={(e) => void handleSubmit(e)}
                className="flex flex-col sm:flex-row gap-3"
                data-testid="waiting-list-form"
              >
                <label htmlFor="waiting-list-email" className="sr-only">
                  {t("emailLabel")}
                </label>
                <input
                  id="waiting-list-email"
                  type="email"
                  required
                  placeholder={t("emailPlaceholder")}
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  disabled={submitting}
                  className="flex-1 rounded-lg border border-background/30 bg-background/10 px-4 py-2 text-sm text-background placeholder:text-background/50 focus:outline-none focus:ring-2 focus:ring-brand"
                  data-testid="waiting-list-email"
                />
                <Button
                  type="submit"
                  disabled={submitting}
                  className="bg-brand hover:bg-brand-hover text-white font-semibold shrink-0"
                  data-testid="waiting-list-submit"
                >
                  {submitting ? t("submitting") : t("submit")}
                </Button>
              </form>
            )}
          </div>
        )}
      </div>
    </section>
  );
}

// ─── Footer ───────────────────────────────────────────────────────────────────

function LandingFooter() {
  const t = useTranslations("landing.footer");
  const year = new Date().getFullYear();

  return (
    <footer
      className="py-12 bg-background border-t"
      data-testid="section-footer"
    >
      <div className="max-w-5xl mx-auto px-4 md:px-6">
        <div className="flex flex-col md:flex-row items-start md:items-center justify-between gap-8">
          {/* Brand */}
          <div>
            <p className="font-bold text-lg text-brand mb-1">
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
                  href="/trips/new"
                  className="hover:text-foreground transition-colors"
                >
                  {t("createTrip")}
                </Link>
              </li>
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
                  href="/legal"
                  className="hover:text-foreground transition-colors"
                >
                  {t("legal")}
                </Link>
              </li>
              <li>
                <a
                  href="https://github.com/vincentchalamon/bike-trip-planner"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex items-center gap-1.5 hover:text-foreground transition-colors"
                  data-testid="footer-github"
                >
                  <Github className="h-3.5 w-3.5" />
                  {t("github")}
                </a>
              </li>
            </ul>
          </nav>
        </div>

        <div className="mt-8 pt-6 border-t text-center text-xs text-muted-foreground">
          {t("copyright", { year })}
        </div>
      </div>
    </footer>
  );
}

// ─── Main export ──────────────────────────────────────────────────────────────

export function LandingPage() {
  return (
    <div className="min-h-screen" data-testid="landing-page">
      <HeroSection />
      <HowItWorksSection />
      <FeaturesSection />
      <SourcesSection />
      <AvailabilitySection />
      <ScreenshotsSection />
      <TestimonialsSection />
      <EarlyAccessSection />
      <LandingFooter />
    </div>
  );
}
