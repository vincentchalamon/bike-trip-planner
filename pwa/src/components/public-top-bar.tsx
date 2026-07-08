import Link from "next/link";
import { useTranslations } from "next-intl";
import { Button } from "@/components/ui/button";
import { Brand } from "@/components/brand";
import { LocaleSwitcher } from "@/components/locale-switcher";
import { ThemeToggle } from "@/components/theme-toggle";
import { cn } from "@/lib/utils";

/**
 * Public (unauthenticated) site header for the landing and other public pages
 * (FAQ, legal, privacy). Mirrors the design's landing header: brand + language
 * pills + theme toggle + "sign in" / "request access" — so language, theme and
 * auth are reachable from the top of the page, not only the footer (audit H1).
 *
 * `transparent` overlays it on the hero (absolute, no border/background) for the
 * landing; the default solid variant suits standalone public pages.
 */
export function PublicTopBar({ transparent = false }: { transparent?: boolean }) {
  const t = useTranslations("navigation");

  return (
    <header
      data-testid="public-top-bar"
      className={cn(
        "z-20 w-full",
        transparent
          ? "absolute inset-x-0 top-0"
          : "border-b border-border bg-background/80 backdrop-blur supports-[backdrop-filter]:bg-background/60",
      )}
    >
      <div className="max-w-[1200px] mx-auto flex items-center gap-1 sm:gap-2 px-3 sm:px-4 md:px-6 h-16">
        <Link
          href="/"
          className="flex items-center hover:opacity-80 transition-opacity shrink-0"
          aria-label={t("brandHome")}
          data-testid="public-top-bar-brand"
        >
          <Brand className="text-base" labelClassName="hidden sm:inline" />
        </Link>

        <div className="flex-1" />

        <div className="flex items-center gap-1">
          <LocaleSwitcher />
          <ThemeToggle />
        </div>

        <Button
          asChild
          variant="outline"
          size="sm"
          className="ml-1"
          data-testid="public-top-bar-login"
        >
          <Link href="/login">{t("login")}</Link>
        </Button>
        <Button
          asChild
          size="sm"
          className="hidden sm:inline-flex bg-brand-fill hover:bg-brand-fill-hover text-white"
          data-testid="public-top-bar-request"
        >
          <Link href="/#early-access">{t("requestAccess")}</Link>
        </Button>
      </div>
    </header>
  );
}
