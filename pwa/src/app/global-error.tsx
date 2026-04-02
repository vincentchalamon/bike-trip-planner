"use client";

export default function GlobalError({
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  // lang hardcoded: GlobalError replaces the root layout so next-intl context is unavailable
  return (
    <html lang="fr">
      <body>
        <div
          style={{
            display: "flex",
            minHeight: "100vh",
            alignItems: "center",
            justifyContent: "center",
            padding: "1rem",
          }}
        >
          <div style={{ textAlign: "center", maxWidth: "24rem" }}>
            <h2 style={{ fontSize: "1.5rem", fontWeight: 600 }}>
              Something went wrong
            </h2>
            <button
              onClick={reset}
              style={{
                marginTop: "1rem",
                padding: "0.5rem 1rem",
                borderRadius: "0.375rem",
                background: "#2563eb",
                color: "#fff",
                border: "none",
                cursor: "pointer",
              }}
            >
              Try again
            </button>
          </div>
        </div>
      </body>
    </html>
  );
}
