import { describe, it, expect, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import "@testing-library/jest-dom/vitest";
import { ManualAccommodationForm } from "./manual-accommodation-form";
import fr from "../../messages/fr.json";

vi.mock("next-intl", async () => {
  const messages = (await import("../../messages/fr.json")).default;
  return {
    useTranslations: (namespace: keyof typeof messages) => (key: string) =>
      (messages[namespace] as Record<string, string>)[key] ??
      `MISSING:${namespace}.${key}`,
  };
});

function renderForm(onSubmit = vi.fn().mockResolvedValue(true)) {
  const onCancel = vi.fn();
  render(<ManualAccommodationForm onSubmit={onSubmit} onCancel={onCancel} />);
  return { onSubmit, onCancel };
}

describe("ManualAccommodationForm", () => {
  it("disables save until title and address are filled", () => {
    renderForm();
    const save = screen.getByRole("button", { name: fr.accommodation.save });
    expect(save).toBeDisabled();

    fireEvent.change(screen.getByLabelText(fr.accommodation.nameLabel), {
      target: { value: "Chez Test" },
    });
    expect(save).toBeDisabled();

    fireEvent.change(screen.getByLabelText(fr.accommodation.addressLabel), {
      target: { value: "10 rue de la Paix" },
    });
    expect(save).toBeEnabled();
  });

  it("submits trimmed values with a parsed total price and url", async () => {
    const { onSubmit } = renderForm();
    fireEvent.change(screen.getByLabelText(fr.accommodation.nameLabel), {
      target: { value: "  Chez Test  " },
    });
    fireEvent.change(screen.getByLabelText(fr.accommodation.addressLabel), {
      target: { value: " 10 rue de la Paix " },
    });
    fireEvent.change(screen.getByLabelText(fr.accommodation.priceTotalLabel), {
      target: { value: "90" },
    });
    fireEvent.change(screen.getByLabelText(fr.accommodation.urlLabel), {
      target: { value: "https://booking.example/x" },
    });
    fireEvent.click(
      screen.getByRole("button", { name: fr.accommodation.save }),
    );

    await waitFor(() =>
      expect(onSubmit).toHaveBeenCalledWith({
        name: "Chez Test",
        address: "10 rue de la Paix",
        priceTotal: 90,
        url: "https://booking.example/x",
      }),
    );
  });

  it("sends a null price when left empty", async () => {
    const { onSubmit } = renderForm();
    fireEvent.change(screen.getByLabelText(fr.accommodation.nameLabel), {
      target: { value: "Chez Test" },
    });
    fireEvent.change(screen.getByLabelText(fr.accommodation.addressLabel), {
      target: { value: "10 rue de la Paix" },
    });
    fireEvent.click(
      screen.getByRole("button", { name: fr.accommodation.save }),
    );

    await waitFor(() =>
      expect(onSubmit).toHaveBeenCalledWith(
        expect.objectContaining({ priceTotal: null, url: null }),
      ),
    );
  });

  it("cancels via the cancel button", () => {
    const { onCancel } = renderForm();
    fireEvent.click(
      screen.getByRole("button", { name: fr.accommodation.cancel }),
    );
    expect(onCancel).toHaveBeenCalledOnce();
  });
});
