"use client";

import { useEffect, useRef, useState } from "react";
import { useParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { AlertTriangle, Loader2, ShieldCheck } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  decidePendingConsent,
  fetchPendingConsent,
  type PendingConsent,
} from "@/lib/api/client";

/**
 * Where an agent asks for access, and the only place it is granted (ADR-079).
 *
 * The browser arrives here from the authorization endpoint, which has already validated the
 * request and parked it server-side. Everything shown is read back from the API rather than
 * from the URL this page was reached on: the client's name, the scopes the server actually
 * validated, and the address the agent will be returned to. A page that rendered the query
 * string would be showing the request rather than what is about to be granted.
 *
 * The decision is POSTed with the session Bearer before the browser goes back. That ordering
 * is the anti-CSRF of the whole flow: the return leg is a plain top-level GET that any page
 * could trigger, and it only completes because a decision is already on file — recorded with
 * a credential that lives in this tab's memory and that no third-party page can produce.
 */
export default function OAuthConsentPage() {
  const t = useTranslations("oauthConsent");
  const params = useParams<{ handle: string }>();
  const [consent, setConsent] = useState<PendingConsent | null>(null);
  const [state, setState] = useState<"loading" | "ready" | "gone" | "working">(
    "loading",
  );
  const loaded = useRef(false);

  useEffect(() => {
    if (loaded.current) return;
    loaded.current = true;

    void (async () => {
      const pending = await fetchPendingConsent(params.handle);
      if (!pending) {
        setState("gone");
        return;
      }
      setConsent(pending);
      setState("ready");
    })();
  }, [params.handle]);

  const decide = async (decision: "approve" | "deny") => {
    if (!consent?.continueUrl) return;
    setState("working");

    if (!(await decidePendingConsent(params.handle, decision))) {
      setState("gone");
      return;
    }

    // Server-relative, built by the API from the request it is holding — never a URL this
    // page composed, and never one the agent supplied.
    //
    // A full navigation, not router.push(): the target is a BACKEND endpoint on the same
    // origin, not a route in this application, and the Next.js router would look for a page
    // that does not exist.
    window.location.href = consent.continueUrl;
  };

  if (state === "loading") {
    return (
      <main className="mx-auto flex w-full max-w-md items-center justify-center py-16">
        <Loader2 className="h-6 w-6 animate-spin" aria-hidden />
        <span className="sr-only">{t("loading")}</span>
      </main>
    );
  }

  if (state === "gone" || !consent) {
    return (
      <main
        className="mx-auto w-full max-w-md py-16 text-center"
        data-testid="oauth-consent-expired"
      >
        <h1 className="font-serif text-xl font-semibold">{t("goneTitle")}</h1>
        <p className="text-muted-foreground mt-2 text-sm">
          {t("goneDescription")}
        </p>
      </main>
    );
  }

  return (
    <main
      className="mx-auto w-full max-w-md py-10"
      style={{ display: "grid", gap: "var(--spacing-xl)" }}
      data-testid="oauth-consent"
    >
      <header
        className="text-center"
        style={{ display: "grid", gap: "var(--spacing-sm)" }}
      >
        <ShieldCheck className="mx-auto h-8 w-8" aria-hidden />
        <h1 className="font-serif text-xl font-semibold tracking-tight">
          {t("title")}
        </h1>
        {/*
          The client's name is text it chose for itself. React escapes it, and it is placed
          on its own line rather than interpolated into a sentence, so it cannot borrow the
          authority of the surrounding wording.
        */}
        <p
          className="text-base font-medium break-words"
          data-testid="oauth-consent-client"
        >
          {consent.clientName}
        </p>
      </header>

      <section
        className="rounded-2xl border bg-card"
        style={{
          padding: "var(--spacing-lg)",
          borderColor: "var(--border)",
          display: "grid",
          gap: "var(--spacing-md)",
        }}
      >
        <p className="text-sm font-medium">{t("scopesTitle")}</p>
        <ul
          className="text-sm"
          style={{ display: "grid", gap: "var(--spacing-xs)" }}
          data-testid="oauth-consent-scopes"
        >
          {(consent.scopes ?? []).map((scope) => (
            <li key={scope}>{t(`scope.${scope}` as never)}</li>
          ))}
        </ul>

        <p className="text-muted-foreground text-xs">
          {t("redirectLabel")}{" "}
          <span className="font-mono" data-testid="oauth-consent-redirect">
            {consent.redirectHost ?? t("redirectUnknown")}
          </span>
        </p>

        {consent.redirectsToLoopback ? (
          <p
            className="flex items-start gap-2 text-xs"
            data-testid="oauth-consent-loopback-warning"
          >
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
            <span>{t("loopbackWarning")}</span>
          </p>
        ) : null}
      </section>

      <div style={{ display: "grid", gap: "var(--spacing-sm)" }}>
        <Button
          onClick={() => void decide("approve")}
          disabled={state === "working"}
          data-testid="oauth-consent-approve"
        >
          {t("approve")}
        </Button>
        <Button
          variant="outline"
          onClick={() => void decide("deny")}
          disabled={state === "working"}
          data-testid="oauth-consent-deny"
        >
          {t("deny")}
        </Button>
      </div>
    </main>
  );
}
