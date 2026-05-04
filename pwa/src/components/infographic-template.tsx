"use client";

import { useEffect, useRef } from "react";
import {
  renderSquareInfographic,
  SQUARE_INFOGRAPHIC_SIZE,
  type SquareInfographicData,
} from "@/lib/infographic-square";

interface InfographicTemplateProps {
  data: SquareInfographicData;
  /** Called once the canvas has finished rendering (or rejected). */
  onReady?: (canvas: HTMLCanvasElement) => void;
  onError?: (err: unknown) => void;
  /** Forwarded to the wrapper element so callers can size the preview. */
  className?: string;
  /** Test id for the underlying <canvas>. */
  canvasTestId?: string;
}

/**
 * Off-screen / preview-friendly React shell that renders the 1080×1080
 * square infographic onto a <canvas>. The canvas is 1:1 by aspect ratio
 * so callers can let CSS shrink it for display while exporting at full
 * resolution via {@link useExportInfographic}.
 */
export function InfographicTemplate({
  data,
  onReady,
  onError,
  className,
  canvasTestId = "infographic-square-canvas",
}: InfographicTemplateProps) {
  const canvasRef = useRef<HTMLCanvasElement>(null);

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    let cancelled = false;
    renderSquareInfographic(canvas, data)
      .then(() => {
        if (!cancelled) onReady?.(canvas);
      })
      .catch((err: unknown) => {
        if (!cancelled) onError?.(err);
      });
    return () => {
      cancelled = true;
    };
  }, [data, onReady, onError]);

  return (
    <div
      className={className}
      style={{ aspectRatio: "1 / 1" }}
      data-testid="infographic-template-wrapper"
    >
      <canvas
        ref={canvasRef}
        className="block w-full h-full"
        width={SQUARE_INFOGRAPHIC_SIZE}
        height={SQUARE_INFOGRAPHIC_SIZE}
        data-testid={canvasTestId}
      />
    </div>
  );
}
