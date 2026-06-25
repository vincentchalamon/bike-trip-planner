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
import { Input } from "@/components/ui/input";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { toast } from "@/components/ui/sonner";
import { useAuthStore } from "@/store/auth-store";
import { requestEmailChange } from "@/lib/api/client";

/**
 * "Mon compte" section: shows the current email and lets the user request an
 * email change. The new address is captured in a dialog and a confirmation
 * link is sent to it (#777) — distinct from the login magic link, which only
 * re-authenticates the current email.
 */
export function AccountSection() {
  const t = useTranslations("accountSettings.account");
  const email = useAuthStore((s) => s.user?.email ?? "");
  const [open, setOpen] = useState(false);
  const [newEmail, setNewEmail] = useState("");
  const [isSending, setIsSending] = useState(false);

  const trimmed = newEmail.trim();
  const isValid = /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(trimmed);

  async function handleSubmit() {
    if (!isValid || isSending) return;
    setIsSending(true);
    try {
      const result = await requestEmailChange(trimmed);
      if (result.ok) {
        toast.success(t("changeEmailSent"));
        setOpen(false);
        setNewEmail("");
      } else {
        toast.error(result.error.message || t("changeEmailFailed"));
      }
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
          onClick={() => setOpen(true)}
          disabled={!email}
          data-testid="change-email-button"
        >
          <Mail className="h-4 w-4" aria-hidden="true" />
          {t("changeEmail")}
        </Button>
      </CardContent>

      <Dialog
        open={open}
        onOpenChange={(v) => {
          setOpen(v);
          if (!v) setNewEmail("");
        }}
      >
        <DialogContent data-testid="change-email-dialog">
          <DialogHeader>
            <DialogTitle>{t("changeEmailDialogTitle")}</DialogTitle>
            <DialogDescription>
              {t("changeEmailDialogDescription")}
            </DialogDescription>
          </DialogHeader>
          <div className="flex flex-col gap-2">
            <label
              htmlFor="new-email"
              className="text-sm font-medium text-muted-foreground"
            >
              {t("newEmailLabel")}
            </label>
            <Input
              id="new-email"
              type="email"
              autoComplete="email"
              value={newEmail}
              onChange={(e) => setNewEmail(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === "Enter") void handleSubmit();
              }}
              placeholder={t("newEmailPlaceholder")}
              data-testid="new-email-input"
            />
          </div>
          <DialogFooter>
            <Button
              variant="outline"
              className="cursor-pointer"
              onClick={() => {
                setOpen(false);
                setNewEmail("");
              }}
              disabled={isSending}
            >
              {t("cancel")}
            </Button>
            <Button
              className="gap-2 cursor-pointer"
              onClick={() => void handleSubmit()}
              disabled={!isValid || isSending}
              data-testid="send-email-change-button"
            >
              {isSending ? (
                <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
              ) : (
                <Mail className="h-4 w-4" aria-hidden="true" />
              )}
              {t("sendChangeLink")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Card>
  );
}
