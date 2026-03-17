export const STAGE_COLORS = [
  "#e63946",
  "#2a9d8f",
  "#e9c46a",
  "#f4a261",
  "#457b9d",
  "#8338ec",
  "#06d6a0",
  "#fb5607",
  "#3a86ff",
  "#ff006e",
];

export function getStageColor(dayNumber: number): string {
  return STAGE_COLORS[(dayNumber - 1) % STAGE_COLORS.length]!;
}
