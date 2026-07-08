import type { Metadata, Viewport } from "next";
import { Fraunces, Inter_Tight, JetBrains_Mono } from "next/font/google";
import { getLocale, getMessages, getTranslations } from "next-intl/server";
import { DEFAULT_LOCALE } from "@/i18n/locale";
import { SITE_URL } from "@/lib/constants";
import "./globals.css";
import { IntlProvider } from "@/components/intl-provider";
import { ThemeProvider } from "@/components/theme-provider";
import { TooltipProvider } from "@/components/ui/tooltip";
import { Toaster } from "@/components/ui/sonner";
import { OnboardingTour } from "@/components/onboarding-tour";
import { AuthGuard } from "@/components/auth-guard";
import { PlausibleScript } from "@/components/plausible-script";

const fraunces = Fraunces({
  subsets: ["latin"],
  variable: "--font-fraunces",
});

const interTight = Inter_Tight({
  subsets: ["latin"],
  variable: "--font-inter-tight",
});

const jetbrainsMono = JetBrains_Mono({
  subsets: ["latin"],
  variable: "--font-jetbrains-mono",
});

// Next 16 requires `themeColor` in the `viewport` export (not in metadata).
// Matches the manifest `theme_color` / `--brand` token, tinting the browser
// UI and the standalone status bar once installed.
export const viewport: Viewport = {
  themeColor: "#a8561a",
};

export async function generateMetadata(): Promise<Metadata> {
  let title = "Bike Trip Planner";
  let description = "Plan your bikepacking trips";
  try {
    const t = await getTranslations("layout");
    title = t("title");
    description = t("description");
  } catch {
    // Static export prerendering: next-intl server context unavailable — keep
    // the English defaults above.
  }

  // Open Graph / Twitter Card on every page (audit 35.2 SEO-003). `metadataBase`
  // makes relative OG URLs absolute; the PWA and API share the origin in
  // iso-prod, so API_URL is the site URL. Per-page metadata (e.g. shared trips)
  // overrides these.
  return {
    metadataBase: new URL(SITE_URL),
    title,
    description,
    // PWA installability (issue #839). `manifest.ts` is served at
    // /manifest.webmanifest by Next's file convention.
    manifest: "/manifest.webmanifest",
    icons: {
      icon: "/icon-192x192.png",
      apple: "/apple-touch-icon.png",
    },
    appleWebApp: {
      capable: true,
      title,
      statusBarStyle: "default",
    },
    openGraph: {
      title,
      description,
      url: "/",
      siteName: "Bike Trip Planner",
      type: "website",
    },
    twitter: {
      card: "summary",
      title,
      description,
    },
  };
}

export default async function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  let locale: string;
  let messages: Awaited<ReturnType<typeof getMessages>>;

  try {
    locale = await getLocale();
    messages = await getMessages();
  } catch {
    // Static export prerendering: next-intl server context unavailable
    locale = DEFAULT_LOCALE;
    messages = (await import(`../../messages/${DEFAULT_LOCALE}.json`)).default;
  }

  return (
    <html
      lang={locale}
      suppressHydrationWarning
      className={`${fraunces.variable} ${interTight.variable} ${jetbrainsMono.variable}`}
    >
      <body className="antialiased overflow-x-hidden">
        <IntlProvider locale={locale} messages={messages}>
          <ThemeProvider
            attribute="class"
            defaultTheme="system"
            enableSystem
            disableTransitionOnChange
          >
            <AuthGuard>
              <TooltipProvider>{children}</TooltipProvider>
              <OnboardingTour />
            </AuthGuard>
            <Toaster richColors position="top-right" />
          </ThemeProvider>
        </IntlProvider>
        <PlausibleScript />
      </body>
    </html>
  );
}
