export interface RiderPreset {
  key: "beginner" | "intermediate" | "expert";
  maxDistancePerDay: number;
  averageSpeed: number;
  elevationPenaltyPercent: number;
  fatiguePercent: number;
}

export const PRESETS: RiderPreset[] = [
  {
    key: "beginner",
    maxDistancePerDay: 50,
    averageSpeed: 10,
    elevationPenaltyPercent: 30,
    fatiguePercent: 30,
  },
  {
    key: "intermediate",
    maxDistancePerDay: 80,
    averageSpeed: 15,
    elevationPenaltyPercent: 20,
    fatiguePercent: 20,
  },
  {
    key: "expert",
    maxDistancePerDay: 120,
    averageSpeed: 20,
    elevationPenaltyPercent: 10,
    fatiguePercent: 10,
  },
];

export function fromFatiguePercent(percent: number): number {
  return 1 - percent / 100;
}

export function fromElevationPercent(percent: number): number {
  return percent * 5;
}

export function toFatiguePercent(factor: number): number {
  return Math.round((1 - factor) * 100);
}

export function toElevationPercent(penalty: number): number {
  return Math.round(penalty / 5);
}

export function getActivePresetKey(
  maxDistancePerDay: number,
  averageSpeed: number,
  elevationPenalty: number,
  fatigueFactor: number,
): RiderPreset["key"] | null {
  return (
    PRESETS.find(
      (p) =>
        p.maxDistancePerDay === maxDistancePerDay &&
        p.averageSpeed === averageSpeed &&
        fromElevationPercent(p.elevationPenaltyPercent) === elevationPenalty &&
        fromFatiguePercent(p.fatiguePercent) === fatigueFactor,
    )?.key ?? null
  );
}
