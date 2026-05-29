"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { useRouter } from "next/navigation";
import { Trash2 } from "lucide-react";
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
import { deleteAccount } from "@/lib/api/client";
import { useAuthStore } from "@/store/auth-store";

/**
 * "Zone de danger" section: account deletion behind a destructive dialog that
 * requires typing the keyword `SUPPRIMER`. On success the user is logged out
 * and redirected home.
 */
export function DangerZoneSection() {
  const t = useTranslations("accountSettings.danger");
  const router = useRouter();
  const logout = useAuthStore((s) => s.logout);
  const [open, setOpen] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);

  async function handleDelete() {
    setIsDeleting(true);
    try {
      const ok = await deleteAccount();
      if (!ok) {
        toast.error(t("deleteFailed"));
        return;
      }
      await logout();
      router.replace("/");
    } catch {
      toast.error(t("deleteFailed"));
    } finally {
      setIsDeleting(false);
      setOpen(false);
    }
  }

  return (
    <Card className="border-destructive" data-testid="danger-zone-section">
      <CardHeader>
        <CardTitle className="text-destructive">{t("title")}</CardTitle>
        <CardDescription>{t("description")}</CardDescription>
      </CardHeader>
      <CardContent>
        <Button
          variant="destructive"
          className="gap-2 cursor-pointer"
          onClick={() => setOpen(true)}
          data-testid="delete-account-button"
        >
          <Trash2 className="h-4 w-4" aria-hidden="true" />
          {t("deleteAccount")}
        </Button>

        <DestructiveDialog
          open={open}
          onOpenChange={setOpen}
          title={t("dialogTitle")}
          description={t("dialogDescription")}
          confirmationKeyword={t("keyword")}
          confirmationLabel={t("confirmLabel")}
          confirmLabel={t("deleteAccount")}
          onConfirm={handleDelete}
          isLoading={isDeleting}
          data-testid="delete-account-dialog"
        />
      </CardContent>
    </Card>
  );
}
