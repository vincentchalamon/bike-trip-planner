import { useRef, useCallback } from "react";

interface SwipeHandlers {
  onTouchStart: (e: React.TouchEvent) => void;
  onTouchEnd: (e: React.TouchEvent) => void;
}

interface UseSwipeOptions {
  onSwipeLeft?: () => void;
  onSwipeRight?: () => void;
  /** Minimum horizontal distance (px) to trigger a swipe. Default: 50. */
  threshold?: number;
}

/**
 * Returns touch event handlers that detect horizontal swipe gestures.
 *
 * Usage:
 *   const swipe = useSwipe({ onSwipeLeft: () => next(), onSwipeRight: () => prev() });
 *   <div {...swipe}>…</div>
 */
export function useSwipe({
  onSwipeLeft,
  onSwipeRight,
  threshold = 50,
}: UseSwipeOptions): SwipeHandlers {
  const startXRef = useRef<number | null>(null);

  const onTouchStart = useCallback((e: React.TouchEvent) => {
    startXRef.current = e.touches[0]?.clientX ?? null;
  }, []);

  const onTouchEnd = useCallback(
    (e: React.TouchEvent) => {
      if (startXRef.current === null) return;
      const endX = e.changedTouches[0]?.clientX ?? startXRef.current;
      const delta = endX - startXRef.current;
      startXRef.current = null;

      if (Math.abs(delta) < threshold) return;

      if (delta < 0) {
        onSwipeLeft?.();
      } else {
        onSwipeRight?.();
      }
    },
    [onSwipeLeft, onSwipeRight, threshold],
  );

  return { onTouchStart, onTouchEnd };
}
