import { cache } from "react";
import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";
import { TripNotFound } from "@/components/trip-not-found";
import { SiteChrome } from "@/components/site-chrome";
import { ShareProvider } from "@/lib/share-context";
import type { SharedTripDetail } from "@/lib/api/client";
import { backendUrl, clientIpHeaders } from "@/lib/auth/backend";
import SharedTripPage from "./shared-trip-page";

/** TripShare::$shortCode: 6 random bytes, base64url-encoded. */
const SHORT_CODE = /^[A-Za-z0-9_-]{8}$/;

/**
 * The public `GET /s/{code}` payload, fetched once per request: `cache()` lets
 * {@link generateMetadata} and the page share the same call. Goes through the
 * INTERNAL backend URL (not the public https origin: avoids the self-signed
 * cert + server-side routing). A revoked/unknown/malformed code, or any error,
 * is `null`; a malformed one never reaches the backend.
 */
const getSharedTrip = cache(
  async (code: string): Promise<SharedTripDetail | null> => {
    if (!SHORT_CODE.test(code)) {
      return null;
    }
    try {
      const res = await fetch(`${backendUrl()}/s/${code}`, {
        headers: {
          Accept: "application/ld+json",
          ...(await clientIpHeaders()),
        },
        cache: "no-store",
        // Bound SSR on a slow/hanging backend (project HTTP convention: 10s).
        signal: AbortSignal.timeout(10_000),
      });
      if (!res.ok) {
        return null;
      }
      return (await res.json()) as SharedTripDetail;
    } catch {
      return null;
    }
  },
);

/**
 * Per-share Open Graph / Twitter metadata (audit 35.2 SEO-001). A missing share
 * falls back to a generic title so the page still renders.
 */
export async function generateMetadata({
  params,
}: {
  params: Promise<{ code: string }>;
}): Promise<Metadata> {
  const { code } = await params;
  const t = await getTranslations("sharedTripMetadata");
  const fallback: Metadata = {
    title: `${t("fallbackTitle")} — Bike Trip Planner`,
  };

  const trip = await getSharedTrip(code);
  if (!trip) {
    return fallback;
  }

  const title = `${trip.title?.trim() || t("defaultTripTitle")} — Bike Trip Planner`;
  const stages = trip.stages ?? [];
  const km = Math.round(stages.reduce((sum, s) => sum + (s.distance ?? 0), 0));
  const dPlus = Math.round(
    stages.reduce((sum, s) => sum + (s.elevation ?? 0), 0),
  );
  const description =
    km > 0
      ? t("description", { distance: km, elevation: dPlus })
      : t("descriptionNoStages");

  return {
    title,
    description,
    openGraph: {
      title,
      description,
      url: `/s/${code}`,
      siteName: "Bike Trip Planner",
      type: "article",
    },
    twitter: { card: "summary", title, description },
  };
}

export default async function Page({
  params,
}: {
  params: Promise<{ code: string }>;
}) {
  const { code } = await params;
  const trip = await getSharedTrip(code);
  if (!trip) {
    return (
      <ShareProvider value={null}>
        <SiteChrome>
          <div data-testid="share-error">
            <TripNotFound variant="share" />
          </div>
        </SiteChrome>
      </ShareProvider>
    );
  }
  return <SharedTripPage code={code} trip={trip} />;
}
