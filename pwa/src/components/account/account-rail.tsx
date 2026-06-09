"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { useRouter } from "next/navigation";
import { Loader2, LogOut, User } from "lucide-react";
import { Button } from "@/components/ui/button";
import { toast } from "@/components/ui/sonner";
import { useAuthStore } from "@/store/auth-store";

/**
 * Account settings left rail (audit #7): identity (avatar + email), the active
 * "My account" nav item, and a red logout action — matching the design's
 * sidebar. Logout lives here (not as a content card), so it carries the
 * `logout-section` / `logout-button` testids that were on `LogoutSection`.
 */
export function AccountRail() {
  const tNav = useTranslations("navigation");
  const t = useTranslations("accountSettings.logout");
  const router = useRouter();
  const email = useAuthStore((s) => s.user?.email ?? "");
  const logout = useAuthStore((s) => s.logout);
  const [isLoggingOut, setIsLoggingOut] = useState(false);
  const initial = email.trim().charAt(0).toUpperCase() || "?";

  async function handleLogout() {
    setIsLoggingOut(true);
    try {
      await logout();
      router.replace("/");
    } catch {
      toast.error(t("logoutFailed"));
    } finally {
      setIsLoggingOut(false);
    }
  }

  return (
    <aside
      className="flex flex-col gap-5 md:sticky md:top-20 md:self-start"
      data-testid="account-rail"
    >
      <div className="flex items-center gap-3">
        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-fill text-sm font-semibold text-white">
          {initial !== "?" ? (
            initial
          ) : (
            <User className="h-5 w-5" aria-hidden="true" />
          )}
        </span>
        <span
          className="min-w-0 truncate text-sm text-muted-foreground"
          title={email}
          data-testid="account-rail-email"
        >
          {email}
        </span>
      </div>

      <nav aria-label={tNav("accountSettings")}>
        <span
          className="block border-l-2 border-brand pl-3 text-sm font-medium text-foreground"
          aria-current="page"
        >
          {tNav("accountSettings")}
        </span>
      </nav>

      <div className="border-t border-border pt-4" data-testid="logout-section">
        <Button
          variant="ghost"
          className="gap-2 cursor-pointer text-red-600 hover:bg-red-50 hover:text-red-700 dark:text-red-400 dark:hover:bg-red-950/30"
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
      </div>
    </aside>
  );
}
