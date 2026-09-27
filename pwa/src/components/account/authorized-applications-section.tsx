"use client";

import { useEffect, useState } from "react";
import { useLocale, useTranslations } from "next-intl";
import { Loader2, Plug, X } from "lucide-react";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { DestructiveDialog } from "@/components/destructive-dialog";
import { toast } from "@/components/ui/sonner";
import {
  fetchAuthorizedApplications,
  revokeAuthorizedApplication,
  type AuthorizedApplication,
} from "@/lib/api/client";

/**
 * "Applications autorisées" section: the agents this account can be acted for by (#1308).
 *
 * ADR-079 shipped a consent screen and no way back. This is the way back, and the only one
 * short of deleting the account.
 *
 * Two things about the rendering are security, not style:
 *
 *  - the application's **name is third-party text**. It is rendered on its own line and never
 *    interpolated into a sentence — not in the row, not in the confirmation dialog — so it
 *    cannot borrow the authority of our own wording. The host goes with it everywhere, because
 *    it is the only part of the identity the application could not choose;
 *  - the confirmation carries **no keyword**. Revoking is reversible (the user re-authorizes
 *    from the agent) and cheap to get wrong in the safe direction; account deletion is neither,
 *    which is why that one asks for a typed word and this one does not.
 */
export function AuthorizedApplicationsSection() {
  const t = useTranslations("accountSettings.applications");
  const scopeT = useTranslations("oauthConsent.scope");
  const locale = useLocale();

  const [applications, setApplications] = useState<
    AuthorizedApplication[] | null
  >(null);
  const [failed, setFailed] = useState(false);
  const [revoking, setRevoking] = useState<AuthorizedApplication | null>(null);
  const [isRevoking, setIsRevoking] = useState(false);

  useEffect(() => {
    void (async () => {
      const list = await fetchAuthorizedApplications();
      if (list === null) {
        setFailed(true);
        return;
      }
      setApplications(list);
    })();
  }, []);

  async function handleRevoke() {
    if (!revoking) return;
    setIsRevoking(true);
    const ok = await revokeAuthorizedApplication(revoking.id);
    setIsRevoking(false);

    if (!ok) {
      toast.error(t("revokeFailed"));
      return;
    }

    // Drop the row locally rather than refetching: the list is small, and the
    // server has just told us this one is gone.
    setApplications((current) =>
      (current ?? []).filter((application) => application.id !== revoking.id),
    );
    setRevoking(null);
    toast.success(t("revoked"));
  }

  return (
    <Card data-testid="authorized-applications-section">
      <CardHeader>
        <CardTitle>{t("title")}</CardTitle>
        <CardDescription>{t("description")}</CardDescription>
      </CardHeader>
      <CardContent>
        {failed ? (
          <p
            className="text-muted-foreground text-sm"
            data-testid="authorized-applications-error"
          >
            {t("loadFailed")}
          </p>
        ) : applications === null ? (
          <p className="text-muted-foreground flex items-center gap-2 text-sm">
            <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
            {t("loading")}
          </p>
        ) : applications.length === 0 ? (
          <p
            className="text-muted-foreground text-sm"
            data-testid="authorized-applications-empty"
          >
            {t("empty")}
          </p>
        ) : (
          <ul className="flex flex-col gap-4">
            {applications.map((application) => (
              <li
                key={application.id}
                className="flex items-start gap-3 rounded-lg border p-3"
                data-testid="authorized-application"
              >
                <Plug
                  className="text-muted-foreground mt-0.5 h-4 w-4 shrink-0"
                  aria-hidden="true"
                />
                <div className="min-w-0 flex-1">
                  <p
                    className="text-sm font-medium break-words"
                    data-testid="authorized-application-name"
                  >
                    {application.name}
                  </p>
                  <p
                    className="text-muted-foreground font-mono text-xs break-all"
                    data-testid="authorized-application-host"
                  >
                    {application.host}
                  </p>
                  <ul className="text-muted-foreground mt-2 text-xs">
                    {application.scopes.map((scope) => (
                      <li key={scope}>{scopeLabel(scope)}</li>
                    ))}
                  </ul>
                  <p className="text-muted-foreground mt-2 text-xs">
                    {t("authorizedOn", {
                      date: formatDate(application.authorizedAt, locale),
                    })}
                    {" · "}
                    {application.lastUsedAt
                      ? t("lastUsedOn", {
                          date: formatDate(application.lastUsedAt, locale),
                        })
                      : t("neverUsed")}
                  </p>
                </div>
                <Button
                  variant="ghost"
                  size="sm"
                  className="cursor-pointer gap-1"
                  onClick={() => setRevoking(application)}
                  data-testid="revoke-application-button"
                >
                  <X className="h-4 w-4" aria-hidden="true" />
                  {t("revoke")}
                </Button>
              </li>
            ))}
          </ul>
        )}

        <DestructiveDialog
          open={revoking !== null}
          onOpenChange={(open) => {
            if (!open) setRevoking(null);
          }}
          title={t("dialogTitle")}
          // The name and host are their own block under the sentence, never inside it.
          description={
            <span className="grid gap-2">
              <span>{t("dialogDescription")}</span>
              <span className="grid">
                <span className="text-foreground font-medium break-words">
                  {revoking?.name}
                </span>
                <span className="font-mono text-xs break-all">
                  {revoking?.host}
                </span>
              </span>
            </span>
          }
          confirmLabel={t("revoke")}
          onConfirm={handleRevoke}
          isLoading={isRevoking}
          data-testid="revoke-application-dialog"
        />
      </CardContent>
    </Card>
  );

  /**
   * The wording the consent screen used when the access was granted, so the same permission
   * does not read as two different things on the two screens.
   *
   * Falls back to the raw scope: these are the scopes **as granted**, which may name a
   * permission the current catalogue no longer describes.
   */
  function scopeLabel(scope: string): string {
    const key = scope as Parameters<typeof scopeT.has>[0];
    return scopeT.has(key) ? scopeT(key) : scope;
  }
}

function formatDate(value: string | null, locale: string): string {
  if (!value) return "";
  return new Intl.DateTimeFormat(locale, {
    day: "numeric",
    month: "short",
    year: "numeric",
  }).format(new Date(value));
}
