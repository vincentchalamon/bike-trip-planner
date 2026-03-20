"use client";

import { useEffect, useRef } from "react";
import { useSearchParams, useRouter } from "next/navigation";
import { isValidUrl } from "@/lib/validation/url";

/**
 * Reads the `?link=` query parameter on mount.
 * If present, calls `onSubmit` with the decoded URL and removes the param
 * from the address bar via `router.replace`.
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
    router.replace("/", { scroll: false });

    if (!isValidUrl(link)) return;

    void onSubmitRef.current(link);
  }, [searchParams, router]);
}
