"use client";

import { useCallback, useRef, useState } from "react";
import {
  downloadSquareInfographicPng,
  renderSquareInfographic,
  type SquareInfographicData,
} from "@/lib/infographic-square";

export interface UseExportInfographicResult {
  /** Bind this ref on the off-screen <canvas> that hosts the template. */
  canvasRef: React.RefObject<HTMLCanvasElement | null>;
  /** Render and trigger a PNG download. */
  exportPng: (data: SquareInfographicData, filename: string) => Promise<void>;
  /** True while the canvas is being rendered/exported. */
  isExporting: boolean;
}

/**
 * Capture & download helper for the 1080×1080 square infographic.
 *
 * The hook owns the canvas ref so callers can place it anywhere (typically a
 * hidden off-screen container). On `exportPng`, it (re)renders the latest
 * data on the canvas and triggers a PNG download via an anchor element.
 */
export function useExportInfographic(): UseExportInfographicResult {
  const canvasRef = useRef<HTMLCanvasElement | null>(null);
  const [isExporting, setIsExporting] = useState(false);

  const exportPng = useCallback(
    async (data: SquareInfographicData, filename: string) => {
      const canvas = canvasRef.current;
      if (!canvas) return;
      setIsExporting(true);
      try {
        await renderSquareInfographic(canvas, data);
        downloadSquareInfographicPng(canvas, filename);
      } finally {
        setIsExporting(false);
      }
    },
    [],
  );

  return { canvasRef, exportPng, isExporting };
}
