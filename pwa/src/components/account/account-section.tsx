"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { Loader2, Mail } from "lucide-react";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { toast } from "@/components/ui/sonner";
import { useAuthStore } from "@/store/auth-store";

/**
 * "Mon compte" section: shows the current email and lets the user trigger an
 * email change through the existing passwordless magic-link flow.
 */
export function AccountSection() {
  const t = useTranslations("accountSettings.account");
  const email = useAuthStore((s) => s.user?.email ?? "");
  const requestMagicLink = useAuthStore((s) => s.requestMagicLink);
  const [isSending, setIsSending] = useState(false);

  async function handleChangeEmail() {
    if (!email) return;
    setIsSending(true);
    try {
      await requestMagicLink(email);
      toast.success(t("changeEmailSent"));
    } catch {
      toast.error(t("changeEmailFailed"));
    } finally {
      setIsSending(false);
    }
  }

  return (
    <Card data-testid="account-section">
      <CardHeader>
        <CardTitle>{t("title")}</CardTitle>
        <CardDescription>{t("description")}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <span className="text-sm font-medium text-muted-foreground">
            {t("emailLabel")}
          </span>
          <span className="text-sm font-medium" data-testid="account-email">
            {email}
          </span>
        </div>
        <Button
          variant="outline"
          className="gap-2 cursor-pointer"
          onClick={() => void handleChangeEmail()}
          disabled={isSending || !email}
          data-testid="change-email-button"
        >
          {isSending ? (
            <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
          ) : (
            <Mail className="h-4 w-4" aria-hidden="true" />
          )}
          {t("changeEmail")}
        </Button>
      </CardContent>
    </Card>
  );
}
