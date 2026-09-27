import type { WeatherPayload } from "@btp/core/mercure";

/**
 * Overwrite one entry of a fixture event's `stages` array with a patched copy (#1291).
 *
 * Every BDD step that says "stage 3 is too long" or "it rains on stage 2" does this, and
 * before this helper they all did it the same wrong way:
 *
 * ```ts
 * const data = event.data as { stages: Array<Record<string, unknown>> };
 * data.stages[0] = { ...data.stages[0], distance: 150 };
 * ```
 *
 * That cast is a lie TypeScript rejects once the folder is type-checked: the payload is
 * `StagePayload[]` or `WeatherPayload[]`, not an open record. Worse, it let a step replace a
 * nested object — `weather` — with a partial one, producing fixture data the shared contract
 * says cannot exist. The component tolerated it; the next reader would not have.
 *
 * So the patch is a **function of the current entry**: it builds on what the fixture already
 * holds instead of inventing a half-object, and stays exactly as typed as the contract.
 *
 * The missing-index throw is deliberate. Under `noUncheckedIndexedAccess` the alternative is
 * spreading `undefined`, which silently yields an object with only the patched fields — a
 * fixture that looks fine and asserts nothing. A step addressing a day the fixture does not
 * have should say so.
 */
export function patchStage<T>(
  stages: T[],
  index: number,
  patch: (current: T) => Partial<T>,
): void {
  const current = stages[index];

  if (current === undefined) {
    throw new Error(
      `patchStage: no stage at index ${index} (the fixture has ${stages.length}).`,
    );
  }

  stages[index] = { ...current, ...patch(current) };
}

/**
 * Patch the nested `weather` object of one entry of a `weather_fetched` payload.
 *
 * Its own helper rather than `patchStage(…, (current) => ({ weather: { ...current.weather, … } }))`
 * for a reason worth knowing: through a generic `Partial<T>`, TypeScript loses the contextual
 * type of the NESTED literal, so `relativeWindDirection: "crosswind"` widens to `string` and is
 * rejected against the union the contract declares. Non-generic here, the literal narrows and
 * the union is actually checked — which is the whole point of type-checking this folder.
 *
 * It also removes the inner spread the callers had to remember: forget it and the fixture ships
 * a `weather` object with half its fields missing, which the contract says cannot exist.
 */
export function patchWeather(
  stages: WeatherPayload[],
  index: number,
  weather: Partial<WeatherPayload["weather"]>,
): void {
  patchStage(stages, index, (current) => ({
    // `{ ...complete, ...partial }` IS complete, but without `exactOptionalPropertyTypes`
    // TypeScript reads `Partial<T>`'s properties as possibly-explicitly-`undefined`, so the
    // spread comes back all-optional. One contained assertion here beats one at each of the
    // eight call sites — and it is the only one in this file.
    weather: { ...current.weather, ...weather } as WeatherPayload["weather"],
  }));
}
