"use client";

import { useEffect } from "react";

interface GlobalErrorProps {
  error: Error & { digest?: string };
  reset: () => void;
}

/**
 * Global error boundary — replaces the root layout, so neither next-intl context
 * nor Tailwind utility classes loaded by the root layout's globals.css can be
 * relied upon. We inline the design tokens (warm paper / ink charcoal / amber)
 * via CSS variables on the root element and use plain inline styles to keep the
 * page self-contained even when CSS fails to load.
 */
export default function GlobalError({ error, reset }: GlobalErrorProps) {
  useEffect(() => {
    console.error(error);
  }, [error]);

  // lang hardcoded: GlobalError replaces the root layout so next-intl context is unavailable
  const title = "Un caillou dans le dérailleur";
  const subtitle =
    "Une erreur critique est survenue. Vous pouvez recharger la page.";
  const requestIdLabel = "Identifiant pour le support :";
  const reloadLabel = "Recharger la page";

  const handleReload = () => {
    // Try Next.js reset first; fall back to a hard reload so the user always
    // has a way out even when the boundary itself is in a bad state.
    try {
      reset();
    } catch {
      if (typeof window !== "undefined") {
        window.location.reload();
      }
    }
  };

  return (
    <html
      lang="fr"
      style={
        {
          // Inline tokens so the page renders even if globals.css failed to load.
          "--surface": "#faf7f0",
          "--ink": "#1a1814",
          "--accent-brand": "#c2671e",
          "--accent-soft": "#fdf0e6",
          "--accent-ink": "#6b2d00",
        } as React.CSSProperties
      }
    >
      <body
        style={{
          margin: 0,
          minHeight: "100vh",
          display: "flex",
          alignItems: "center",
          justifyContent: "center",
          padding: "1rem",
          background: "var(--surface)",
          color: "var(--ink)",
          fontFamily:
            "var(--font-sans), -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
        }}
      >
        <div
          data-testid="global-error-page"
          style={{
            textAlign: "center",
            maxWidth: "28rem",
            display: "flex",
            flexDirection: "column",
            gap: "1.5rem",
            alignItems: "center",
          }}
        >
          <div
            aria-hidden="true"
            style={{
              width: "5rem",
              height: "5rem",
              borderRadius: "9999px",
              background: "var(--accent-soft)",
              color: "var(--accent-ink)",
              display: "inline-flex",
              alignItems: "center",
              justifyContent: "center",
            }}
          >
            <svg
              viewBox="0 0 24 24"
              width="40"
              height="40"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <circle cx="12" cy="12" r="10" />
              <line x1="12" y1="8" x2="12" y2="12" />
              <line x1="12" y1="16" x2="12.01" y2="16" />
            </svg>
          </div>

          <div
            style={{ display: "flex", flexDirection: "column", gap: "0.75rem" }}
          >
            <h1
              data-testid="global-error-title"
              style={{
                margin: 0,
                fontFamily:
                  "var(--font-serif), Georgia, 'Times New Roman', serif",
                fontSize: "2rem",
                fontWeight: 600,
                letterSpacing: "-0.01em",
                lineHeight: 1.1,
              }}
            >
              {title}
            </h1>
            <p
              data-testid="global-error-subtitle"
              style={{
                margin: 0,
                fontSize: "1rem",
                lineHeight: 1.5,
                color: "var(--ink)",
                opacity: 0.7,
              }}
            >
              {subtitle}
            </p>
          </div>

          {error.digest ? (
            <p
              data-testid="global-error-request-id"
              style={{
                margin: 0,
                fontFamily:
                  "var(--font-mono), ui-monospace, SFMono-Regular, Menlo, monospace",
                fontSize: "0.8125rem",
                color: "var(--ink)",
                opacity: 0.6,
                wordBreak: "break-all",
              }}
            >
              <span style={{ marginRight: "0.5rem" }}>{requestIdLabel}</span>
              <span data-testid="global-error-digest">{error.digest}</span>
            </p>
          ) : null}

          <button
            onClick={handleReload}
            data-testid="global-error-reload-button"
            style={{
              padding: "0.625rem 1.25rem",
              borderRadius: "0.5rem",
              background: "var(--accent-brand)",
              color: "#ffffff",
              border: "none",
              cursor: "pointer",
              fontSize: "0.9375rem",
              fontWeight: 500,
              fontFamily: "inherit",
              transition: "filter 0.15s ease",
            }}
            onMouseEnter={(e) => {
              e.currentTarget.style.filter = "brightness(0.92)";
            }}
            onMouseLeave={(e) => {
              e.currentTarget.style.filter = "brightness(1)";
            }}
            onFocus={(e) => {
              e.currentTarget.style.outline = "3px solid var(--accent-brand)";
              e.currentTarget.style.outlineOffset = "3px";
            }}
            onBlur={(e) => {
              e.currentTarget.style.outline = "none";
            }}
          >
            {reloadLabel}
          </button>
        </div>
      </body>
    </html>
  );
}
