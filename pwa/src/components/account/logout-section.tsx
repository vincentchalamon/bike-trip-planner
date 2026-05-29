"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { useRouter } from "next/navigation";
import { Loader2, LogOut } from "lucide-react";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { useAuthStore } from "@/store/auth-store";

/**
 * "Déconnexion" section: logs the user out and redirects home.
 */
export function LogoutSection() {
  const t = useTranslations("accountSettings.logout");
  const router = useRouter();
  const logout = useAuthStore((s) => s.logout);
  const [isLoggingOut, setIsLoggingOut] = useState(false);

  async function handleLogout() {
    setIsLoggingOut(true);
    try {
      await logout();
      router.replace("/");
    } finally {
      setIsLoggingOut(false);
    }
  }

  return (
    <Card data-testid="logout-section">
      <CardHeader>
        <CardTitle>{t("title")}</CardTitle>
        <CardDescription>{t("description")}</CardDescription>
      </CardHeader>
      <CardContent>
        <Button
          variant="outline"
          className="gap-2 cursor-pointer"
          onClick={() => void handleLogout()}
          disabled={isLoggingOut}
          data-testid="logout-button"
        >
          {isLoggingOut ? (
            <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
          ) : (
            <LogOut className="h-4 w-4" aria-hidden="true" />
          )}
          {t("button")}
        </Button>
      </CardContent>
    </Card>
  );
}
