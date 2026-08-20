// Pure decision for the roadbook/map horizontal swipe: a decisive left swipe
// (negative dx) selects the map, a decisive right swipe (positive dx) selects
// the roadbook, anything shorter than the threshold does nothing. Extracted so
// the gesture wiring stays declarative and the mapping is unit-testable without
// PanResponder's touch-history internals.
export type SwipeView = 'roadbook' | 'map';

export function swipeToView(
  dx: number,
  threshold = 48,
): SwipeView | null {
  if (dx <= -threshold) return 'map';
  if (dx >= threshold) return 'roadbook';
  return null;
}
