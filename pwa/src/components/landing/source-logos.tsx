/**
 * Brand marks for the "Sources supportées" section (#649).
 *
 * Official-style SVG logos for Komoot, Strava and RideWithGPS are shipped
 * locally (no third-party network request, no bitmap assets). Each renders at
 * its brand colour. The GPX mark uses `currentColor` so it adapts to the
 * light/dark theme (the previous near-black glyph was invisible in dark mode).
 */

interface LogoProps {
  className?: string;
}

/** Komoot wordmark "k" monogram. */
export function KomootLogo({ className }: LogoProps) {
  return (
    <svg
      viewBox="0 0 24 24"
      className={className}
      fill="#6AA127"
      aria-hidden="true"
    >
      <circle cx="12" cy="12" r="12" fill="#6AA127" />
      <path
        d="M8 5.5h2.1v5.2l4-5.2h2.5l-4.2 5.4 4.4 6.1h-2.6l-3.2-4.6-.9 1.1v3.5H8z"
        fill="#fff"
      />
    </svg>
  );
}

/** Strava chevron mark. */
export function StravaLogo({ className }: LogoProps) {
  return (
    <svg viewBox="0 0 24 24" className={className} aria-hidden="true">
      <path
        d="M10.8 2.5 4.5 14.9h3.7l2.6-5.2 2.6 5.2h3.6z"
        fill="#FC4C02"
      />
      <path
        d="M14.4 14.9l-1.9 3.8-1.9-3.8H8.1l4.4 8.6 4.4-8.6z"
        fill="#FC4C02"
        opacity="0.55"
      />
    </svg>
  );
}

/** RideWithGPS "R" monogram. */
export function RideWithGpsLogo({ className }: LogoProps) {
  return (
    <svg viewBox="0 0 24 24" className={className} aria-hidden="true">
      <circle cx="12" cy="12" r="12" fill="#E63022" />
      <path
        d="M8.4 5.6h4.3c2.2 0 3.7 1.3 3.7 3.3 0 1.5-.8 2.5-2.1 3l2.4 4.5h-2.5l-2.1-4.1h-1.5v4.1H8.4zm2.6 4.8h1.5c1 0 1.6-.5 1.6-1.4s-.6-1.4-1.6-1.4h-1.5z"
        fill="#fff"
      />
    </svg>
  );
}

/** GPX file mark — adapts to the theme via `currentColor`. */
export function GpxLogo({ className }: LogoProps) {
  return (
    <svg
      viewBox="0 0 24 24"
      className={className}
      fill="none"
      stroke="currentColor"
      strokeWidth="1.6"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
      <path d="M14 2v6h6" />
      <text
        x="12"
        y="17.5"
        textAnchor="middle"
        fontSize="5.5"
        fontWeight="700"
        fill="currentColor"
        stroke="none"
      >
        GPX
      </text>
    </svg>
  );
}
