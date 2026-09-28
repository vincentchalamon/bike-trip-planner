import type { ComponentProps } from "react";
import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, act } from "@testing-library/react";
import "@testing-library/jest-dom/vitest";
import { ConfigPanel } from "./config-panel";
import { useUiStore } from "@/store/ui-store";

vi.mock("next-intl", () => ({
  useTranslations: () => (key: string) => key,
}));
vi.mock("@/components/pacing-settings", () => ({
  PacingSettings: () => null,
}));
vi.mock("@/components/date-range-picker", () => ({
  DateRangePicker: () => null,
}));

const baseProps: ComponentProps<typeof ConfigPanel> = {
  startDate: null,
  endDate: null,
  onDatesChange: () => {},
  fatigueFactor: 0.9,
  elevationPenalty: 50,
  maxDistancePerDay: 80,
  averageSpeed: 15,
  ebikeMode: false,
  departureHour: 8,
  enabledAccommodationTypes: ["camp_site", "hotel"],
  onPacingUpdate: () => {},
  onPacingCommit: () => {},
  onEbikeModeChange: () => {},
  onDepartureHourChange: () => {},
  onAccommodationTypesChange: () => {},
  hasTripLoaded: true,
  onDuplicate: () => Promise.resolve(null),
  onShare: () => {},
};

const setOpen = (open: boolean) =>
  act(() => useUiStore.getState().setConfigPanelOpen(open));

describe("ConfigPanel", () => {
  beforeEach(() => {
    useUiStore.setState({ isConfigPanelOpen: false });
  });

  it("takes the closed panel out of the tab order", () => {
    render(<ConfigPanel {...baseProps} />);

    const panel = screen.getByRole("dialog", { hidden: true });
    expect(panel).toHaveAttribute("inert");

    setOpen(true);
    expect(panel).not.toHaveAttribute("inert");
  });

  it("restores focus to the trigger when the panel closes", () => {
    render(
      <>
        <button type="button">trigger</button>
        <ConfigPanel {...baseProps} />
      </>,
    );
    const trigger = screen.getByRole("button", { name: "trigger" });
    trigger.focus();

    setOpen(true);
    expect(document.activeElement).not.toBe(trigger);

    setOpen(false);
    expect(document.activeElement).toBe(trigger);
  });

  it("wraps Tab from a control rendered after the panel opened", () => {
    const { rerender } = render(<ConfigPanel {...baseProps} />);
    setOpen(true);

    rerender(<ConfigPanel {...baseProps} onDelete={() => {}} />);
    const deleteButton = screen.getByTestId("delete-trip-button");
    deleteButton.focus();

    const event = fireEvent.keyDown(deleteButton, { key: "Tab" });

    expect(event).toBe(false);
    expect(document.activeElement).toBe(
      screen.getByRole("button", { name: "close" }),
    );
  });
});
