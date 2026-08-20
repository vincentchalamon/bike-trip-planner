// Per-stage line color, mirroring the web map's one-color-per-stage scheme
// (pwa/src/components/Map/stage-colors.ts) so the mobile map distinguishes each
// stage the same way. The web ships a fixed 10-hex palette; the mobile theme
// forbids new raw hexes, and a fixed cycling palette also risks placing
// look-alike hues on adjacent stages of a long trip (its 1st and 11th stages
// share a hex, and neighbours like its 10th pink / 11th red read as close).
//
// Strategy: compute the hue by rotating the color wheel by the golden angle
// (~137.5 deg) per stage. Consecutive stages therefore always land far apart on
// the wheel (circular hue distance ~137.5 deg between neighbours), guaranteeing
// contrast between adjacent stages for any trip length, and the sequence only
// starts repeating after hundreds of stages. Saturation/lightness are fixed for
// legibility over both the light base map and satellite imagery; the base hue is
// anchored near the theme's warm brand orange (#a8561a) for visual continuity.
// This computed color is the documented exception to the "no raw hex" rule.
const GOLDEN_ANGLE = 137.508;
const BASE_HUE = 25; // ~ brand orange
const SATURATION = 72;
const LIGHTNESS = 48;

// `dayNumber` is the web's 1-based stage key; any integer works since the hue
// wraps mod 360.
export function stageColor(dayNumber: number): string {
  const hue = (((BASE_HUE + (dayNumber - 1) * GOLDEN_ANGLE) % 360) + 360) % 360;
  return `hsl(${hue.toFixed(1)}, ${SATURATION}%, ${LIGHTNESS}%)`;
}
