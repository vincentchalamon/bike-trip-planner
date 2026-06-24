import { afterEach, describe, expect, it, vi } from "vitest";
import { isAiFeatureEnabled } from "./constants";

afterEach(() => {
  vi.unstubAllEnvs();
});

describe("isAiFeatureEnabled (recette #649)", () => {
  it('is true only when NEXT_PUBLIC_ENABLE_AI is exactly "true"', () => {
    vi.stubEnv("NEXT_PUBLIC_ENABLE_AI", "true");
    expect(isAiFeatureEnabled()).toBe(true);
  });

  it("is false (masked) for any other value or when unset", () => {
    for (const value of ["false", "", "1", "TRUE", "yes"]) {
      vi.stubEnv("NEXT_PUBLIC_ENABLE_AI", value);
      expect(isAiFeatureEnabled()).toBe(false);
    }
    vi.stubEnv("NEXT_PUBLIC_ENABLE_AI", undefined);
    expect(isAiFeatureEnabled()).toBe(false);
  });
});
