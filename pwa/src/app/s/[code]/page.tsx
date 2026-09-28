import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";
import SharedTripPage from "./shared-trip-page";

/** Minimal shape of the public shared-trip payload used for metadata. */
interface SharedTripMeta {
  title?: string | null;
  stages?: { distance?: number | null; elevation?: number | null }[] | null;
}

/**
 * Per-share Open Graph / Twitter metadata (audit 35.2 SEO-001). Fetched
 * server-side from the public `GET /s/{code}` endpoint via the INTERNAL backend
 * URL (not the public https origin: avoids the self-signed cert + server-side
 * routing). Defensive: a revoked/unknown code (or any error) falls back to a
 * generic title so the page still renders.
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

  try {
    const backend = process.env.API_BACKEND_URL ?? "http://php";
    const res = await fetch(`${backend}/s/${encodeURIComponent(code)}`, {
      headers: { Accept: "application/ld+json" },
      cache: "no-store",
      // Bound SSR on a slow/hanging backend (project HTTP convention: 10s). An
      // AbortError is caught below and falls back to the generic metadata.
      signal: AbortSignal.timeout(10_000),
    });
    if (!res.ok) {
      return fallback;
    }

    const trip = (await res.json()) as SharedTripMeta;
    const title = `${trip.title?.trim() || t("defaultTripTitle")} — Bike Trip Planner`;
    const stages = trip.stages ?? [];
    const km = Math.round(
      stages.reduce((sum, s) => sum + (s.distance ?? 0), 0),
    );
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
  } catch {
    return fallback;
  }
}

export default async function Page({
  params,
}: {
  params: Promise<{ code: string }>;
}) {
  const { code } = await params;
  return <SharedTripPage code={code} />;
}
