"use client";

import { useEffect, useRef } from "react";
import { useSearchParams, useRouter } from "next/navigation";
import { isValidUrl } from "@/lib/validation/url";

/**
 * Reads the `?link=` query parameter on mount.
 * If present and valid, hands the decoded URL to `onSubmit` (which creates the
 * trip and redirects to it). An invalid link is dropped from the address bar.
 *
 * Must be rendered inside a Suspense boundary for static export compatibility.
 */
export function useLinkParam(onSubmit: (url: string) => Promise<void>) {
  const searchParams = useSearchParams();
  const router = useRouter();
  const consumedRef = useRef(false);
  const onSubmitRef = useRef(onSubmit);
  useEffect(() => {
    onSubmitRef.current = onSubmit;
  });

  useEffect(() => {
    if (consumedRef.current) return;

    const link = searchParams.get("link");
    if (!link) return;

    consumedRef.current = true;

    if (!isValidUrl(link)) {
      // Unusable link: just strip the param so a reload doesn't re-trigger it.
      router.replace("/", { scroll: false });
      return;
    }

    // Valid link: hand it to the planner, which redirects to /trips/{id} on
    // success. Crucially we do NOT also `router.replace("/")` here — that extra
    // navigation to the home route raced the trip-creation redirect (the real
    // POST takes seconds, vs. an instant mock in tests), stranding the planner
    // on "/" so the trip never opened (recette #649 #8). `onSubmit` resolves the
    // address bar by navigating away; a failed creation simply leaves `?link=`
    // in place — a sensible retry-on-reload.
    void onSubmitRef.current(link);
  }, [searchParams, router]);
}
