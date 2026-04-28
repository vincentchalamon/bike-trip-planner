import type { Metadata } from "next";
import { Fraunces, Inter_Tight, JetBrains_Mono } from "next/font/google";
import { NextIntlClientProvider } from "next-intl";
import { getLocale, getMessages, getTranslations } from "next-intl/server";
import { DEFAULT_LOCALE } from "@/i18n/locale";
import "./globals.css";
import { ThemeProvider } from "@/components/theme-provider";
import { TooltipProvider } from "@/components/ui/tooltip";
import { Toaster } from "@/components/ui/sonner";
import { OnboardingTour } from "@/components/onboarding-tour";
import { AuthGuard } from "@/components/auth-guard";

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

export async function generateMetadata(): Promise<Metadata> {
  try {
    const t = await getTranslations("layout");
    return {
      title: t("title"),
      description: t("description"),
    };
  } catch {
    return {
      title: "Bike Trip Planner",
      description: "Plan your bikepacking trips",
    };
  }
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
        <NextIntlClientProvider locale={locale} messages={messages}>
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
        </NextIntlClientProvider>
      </body>
    </html>
  );
}
