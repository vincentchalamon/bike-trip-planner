import { describe, it, expect, vi, afterEach } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import "@testing-library/jest-dom/vitest";
import { DestructiveDialog } from "./destructive-dialog";

vi.mock("next-intl", () => ({
  useTranslations: () => (key: string, params?: Record<string, string>) =>
    params ? `${key}:${JSON.stringify(params)}` : key,
}));

describe("DestructiveDialog", () => {
  afterEach(() => {
    vi.clearAllMocks();
  });

  it("renders the title and description when open", () => {
    render(
      <DestructiveDialog
        open
        onOpenChange={() => {}}
        title="Delete trip?"
        description="This cannot be undone."
        onConfirm={() => {}}
      />,
    );

    expect(screen.getByText("Delete trip?")).toBeInTheDocument();
    expect(screen.getByText("This cannot be undone.")).toBeInTheDocument();
  });

  it("invokes onConfirm when the destructive button is clicked (no keyword)", () => {
    const onConfirm = vi.fn();
    render(
      <DestructiveDialog
        open
        onOpenChange={() => {}}
        title="Delete?"
        description="Gone forever."
        onConfirm={onConfirm}
      />,
    );

    fireEvent.click(screen.getByTestId("destructive-dialog-confirm"));
    expect(onConfirm).toHaveBeenCalledTimes(1);
  });

  it("disables the destructive button until the keyword is typed", () => {
    const onConfirm = vi.fn();
    render(
      <DestructiveDialog
        open
        onOpenChange={() => {}}
        title="Delete account?"
        description="All data will be lost."
        confirmationKeyword="SUPPRIMER"
        onConfirm={onConfirm}
      />,
    );

    const button = screen.getByTestId(
      "destructive-dialog-confirm",
    ) as HTMLButtonElement;
    expect(button.disabled).toBe(true);

    const input = screen.getByTestId("destructive-dialog-keyword-input");
    fireEvent.change(input, { target: { value: "wrong" } });
    expect(button.disabled).toBe(true);

    fireEvent.change(input, { target: { value: "SUPPRIMER" } });
    expect(button.disabled).toBe(false);

    fireEvent.click(button);
    expect(onConfirm).toHaveBeenCalledTimes(1);
  });

  it("invokes onOpenChange(false) when the cancel button is clicked", () => {
    const onOpenChange = vi.fn();
    render(
      <DestructiveDialog
        open
        onOpenChange={onOpenChange}
        title="Delete?"
        description="Gone forever."
        onConfirm={() => {}}
      />,
    );

    fireEvent.click(screen.getByTestId("destructive-dialog-cancel"));
    expect(onOpenChange).toHaveBeenCalledWith(false);
  });
});
