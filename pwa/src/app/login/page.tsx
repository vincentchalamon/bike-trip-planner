"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { useAuthStore } from "@/store/auth-store";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { AttributionFooter } from "@/components/attribution-footer";

export default function LoginPage() {
  const t = useTranslations("auth");
  const tEarlyAccess = useTranslations("earlyAccess");
  const tFooter = useTranslations("footer");
  const router = useRouter();
  const { isAuthenticated, requestMagicLink } = useAuthStore();
  const [email, setEmail] = useState("");
  const [submitted, setSubmitted] = useState(false);
  const [loading, setLoading] = useState(false);

  // Redirect if already authenticated
  useEffect(() => {
    if (isAuthenticated) {
      router.replace("/");
    }
  }, [isAuthenticated, router]);

  if (isAuthenticated) {
    return null;
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!email.trim()) return;

    setLoading(true);
    try {
      await requestMagicLink(email.trim());
    } catch {
      // Swallow network errors — UI always shows confirmation (anti-enumeration)
    } finally {
      setLoading(false);
      setSubmitted(true);
    }
  };

  return (
    <div className="flex min-h-screen flex-col items-center justify-center p-4">
      <div className="w-full max-w-sm space-y-6">
        <div className="space-y-2 text-center">
          <h1 className="text-2xl font-bold tracking-tight">
            {t("loginTitle")}
          </h1>
          <p className="text-muted-foreground text-sm">
            {t("loginDescription")}
          </p>
        </div>

        {submitted ? (
          <div
            className="bg-muted rounded-md p-4 text-center text-sm"
            role="status"
          >
            {t("linkSent")}
          </div>
        ) : (
          <form onSubmit={(e) => void handleSubmit(e)} className="space-y-4">
            <div className="space-y-2">
              <label htmlFor="email" className="text-sm font-medium">
                {t("emailLabel")}
              </label>
              <Input
                id="email"
                type="email"
                placeholder={t("emailPlaceholder")}
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
                autoComplete="email"
                autoFocus
                disabled={loading}
              />
            </div>
            <Button type="submit" className="w-full" disabled={loading}>
              {loading ? t("sending") : t("sendLink")}
            </Button>
          </form>
        )}

        {/* Early access info box — for users not yet registered */}
        <div
          className="rounded-lg border border-muted bg-muted/40 p-4 space-y-2 text-center"
          data-testid="early-access-banner"
        >
          <p className="text-sm font-medium">{tEarlyAccess("loginBoxTitle")}</p>
          <p className="text-xs text-muted-foreground">
            {tEarlyAccess("loginBoxDescription")}
          </p>
          <Link
            href="/#early-access"
            className="inline-block text-xs text-primary underline underline-offset-4 hover:text-primary/80"
            data-testid="early-access-link"
          >
            {tEarlyAccess("loginBoxCta")}
          </Link>
        </div>
      </div>
      <footer className="mt-8 text-center space-y-2">
        <div>
          <Link
            href="/faq"
            className="text-xs text-muted-foreground hover:text-foreground transition-colors"
            data-testid="footer-faq-link"
          >
            {tFooter("faq")}
          </Link>
        </div>
        <div>
          <AttributionFooter />
        </div>
      </footer>
    </div>
  );
}
