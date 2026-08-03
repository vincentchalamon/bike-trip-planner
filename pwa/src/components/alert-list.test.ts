import { describe, it, expect } from "vitest";
import { alertKey } from "@/components/alert-list";
import type { AlertData } from "@/lib/validation/schemas";

function alert(overrides: Partial<AlertData> = {}): AlertData {
  return {
    type: "warning",
    code: "resupply_closed_at_passage",
    message: "Des commerces existent, mais tu passerais hors des horaires.",
    lat: 45.1,
    lon: 4.2,
    ...overrides,
  } as AlertData;
}

describe("alertKey", () => {
  it("does not change when the message is reworded", () => {
    const before = alertKey(alert(), 0);
    const after = alertKey(
      alert({ message: "Tu passerais en dehors des heures d'ouverture." }),
      0,
    );

    expect(after).toBe(before);
  });

  it("keeps two variants of the same family apart", () => {
    expect(alertKey(alert({ code: "ford_crossing_dry" }), 0)).not.toBe(
      alertKey(alert({ code: "ford_crossing_wet" }), 0),
    );
  });

  it("keeps two occurrences of one rule at different places apart", () => {
    expect(alertKey(alert(), 0)).not.toBe(
      alertKey(alert({ lat: 46.9, lon: 3.1 }), 1),
    );
  });

  it("falls back to the message for an alert persisted before codes existed", () => {
    const legacy = alert({ code: undefined });

    expect(alertKey(legacy, 0)).toContain(legacy.message);
    expect(alertKey(legacy, 0)).not.toBe(
      alertKey(alert({ code: undefined, message: "Autre formulation." }), 0),
    );
  });
});
