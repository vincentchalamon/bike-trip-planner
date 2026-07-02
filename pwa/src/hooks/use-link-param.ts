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

    // Strip the param from the CURRENT history entry before handing off, so that
    // pressing Back from the resulting /trips/{id} lands on a clean "/" and does
    // not re-mount this hook (fresh consumedRef) → which would create a duplicate
    // trip on every Back (review on #800). We use window.history.replaceState —
    // a Next-supported in-place URL rewrite that syncs with useSearchParams —
    // rather than router.replace("/"): the latter is a real navigation that raced
    // the trip-creation redirect and stranded the planner on "/" (recette #649 #8).
    window.history.replaceState(null, "", "/");

    // Hand the link to the planner, which redirects to /trips/{id} on success.
    // A failed creation leaves the user on "/" — they can retry from the planner.
    void onSubmitRef.current(link);
  }, [searchParams, router]);
}
