"use client";

import { useCallback, useState } from "react";

/**
 * Reason codes for a geolocation failure.
 *
 * - `denied`: the user (or browser policy) refused permission.
 * - `unavailable`: the device cannot determine a position.
 * - `timeout`: the request did not complete within the timeout.
 * - `unsupported`: the `navigator.geolocation` API is not available.
 */
export type GeolocationErrorCode =
  | "denied"
  | "unavailable"
  | "timeout"
  | "unsupported";

export interface GeolocationCoords {
  latitude: number;
  longitude: number;
  accuracy: number;
  timestamp: number;
}

export interface GeolocationErrorInfo {
  code: GeolocationErrorCode;
  message: string;
}

export interface UseGeolocationResult {
  position: GeolocationCoords | null;
  error: GeolocationErrorInfo | null;
  isRequesting: boolean;
  request: () => void;
}

const DEFAULT_OPTIONS: PositionOptions = {
  enableHighAccuracy: false,
  timeout: 10_000,
  maximumAge: 60_000,
};

/**
 * One-shot geolocation hook.
 *
 * Intentionally avoids `watchPosition` to preserve battery on mobile devices.
 * Each call to `request()` triggers a single `getCurrentPosition()` lookup
 * with battery-friendly options (low accuracy, 60s cache, 10s timeout).
 */
export function useGeolocation(): UseGeolocationResult {
  const [position, setPosition] = useState<GeolocationCoords | null>(null);
  const [error, setError] = useState<GeolocationErrorInfo | null>(null);
  const [isRequesting, setIsRequesting] = useState(false);

  const request = useCallback(() => {
    if (
      typeof navigator === "undefined" ||
      !("geolocation" in navigator) ||
      !navigator.geolocation
    ) {
      setError({
        code: "unsupported",
        message: "Geolocation API is not available in this environment.",
      });
      return;
    }

    setIsRequesting(true);
    setError(null);

    navigator.geolocation.getCurrentPosition(
      (pos) => {
        setPosition({
          latitude: pos.coords.latitude,
          longitude: pos.coords.longitude,
          accuracy: pos.coords.accuracy,
          timestamp: pos.timestamp,
        });
        setIsRequesting(false);
      },
      (err) => {
        const code: GeolocationErrorCode =
          err.code === 1
            ? "denied"
            : err.code === 3
              ? "timeout"
              : "unavailable";
        setError({ code, message: err.message });
        setIsRequesting(false);
      },
      DEFAULT_OPTIONS,
    );
  }, []);

  return { position, error, isRequesting, request };
}
