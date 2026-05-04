import { ScreenshotsSection } from "@/components/screenshots-section";
import { EarlyAccessSection } from "@/components/early-access-section";
import {
  LandingHero,
  LandingHowItWorks,
  LandingBentoGrid,
  LandingSources,
  LandingPlatforms,
  LandingTestimonials,
  LandingFooter,
} from "@/components/landing";

/**
 * Public landing page (issue #400 — sprint 27).
 *
 * Composed of small section components living under `@/components/landing/`.
 * The page deliberately keeps the existing data-testid contract used by
 * tests/mocked/landing-page.spec.ts — section testids and CTA testids must
 * remain stable.
 */
export function LandingPage() {
  return (
    <div className="min-h-screen bg-background" data-testid="landing-page">
      <LandingHero />
      <LandingHowItWorks />
      <LandingBentoGrid />
      <LandingSources />
      <LandingPlatforms />
      <ScreenshotsSection />
      <LandingTestimonials />
      <EarlyAccessSection />
      <LandingFooter />
    </div>
  );
}
