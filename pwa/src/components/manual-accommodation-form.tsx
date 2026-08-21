"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";

export interface ManualAccommodationInput {
  name: string;
  address: string;
  priceTotal: number | null;
  url: string | null;
}

interface ManualAccommodationFormProps {
  onSubmit: (data: ManualAccommodationInput) => Promise<boolean>;
  onCancel: () => void;
}

/**
 * Inline form for a hors-app accommodation: title + address (both required),
 * total price and link (optional). The address is geocoded backend-side, so the
 * produced object is a first-class, selected Accommodation (source "manual").
 */
export function ManualAccommodationForm({
  onSubmit,
  onCancel,
}: ManualAccommodationFormProps) {
  const t = useTranslations("accommodation");
  const [name, setName] = useState("");
  const [address, setAddress] = useState("");
  const [priceTotal, setPriceTotal] = useState("");
  const [url, setUrl] = useState("");
  const [submitting, setSubmitting] = useState(false);

  const canSubmit = name.trim() !== "" && address.trim() !== "" && !submitting;

  async function handleSubmit() {
    if (!canSubmit) return;
    setSubmitting(true);
    const parsedPrice = parseFloat(priceTotal);
    const ok = await onSubmit({
      name: name.trim(),
      address: address.trim(),
      priceTotal: Number.isFinite(parsedPrice) ? parsedPrice : null,
      url: url.trim() === "" ? null : url.trim(),
    });
    setSubmitting(false);
    if (ok) {
      setName("");
      setAddress("");
      setPriceTotal("");
      setUrl("");
    }
  }

  function handleKeyDown(e: React.KeyboardEvent) {
    if (e.key === "Enter") {
      void handleSubmit();
    } else if (e.key === "Escape") {
      onCancel();
    }
  }

  return (
    <div className="py-2 space-y-2" data-testid="manual-accommodation-form">
      <Input
        value={name}
        onChange={(e) => setName(e.target.value)}
        onKeyDown={handleKeyDown}
        placeholder={t("namePlaceholder")}
        className="h-7 text-sm"
        aria-label={t("nameLabel")}
        autoFocus
      />
      <Input
        value={address}
        onChange={(e) => setAddress(e.target.value)}
        onKeyDown={handleKeyDown}
        placeholder={t("addressPlaceholder")}
        className="h-7 text-sm"
        aria-label={t("addressLabel")}
      />
      <Input
        type="number"
        value={priceTotal}
        onChange={(e) => setPriceTotal(e.target.value)}
        onKeyDown={handleKeyDown}
        placeholder={t("priceTotalPlaceholder")}
        className="h-7 text-sm"
        aria-label={t("priceTotalLabel")}
      />
      <Input
        value={url}
        onChange={(e) => setUrl(e.target.value)}
        onKeyDown={handleKeyDown}
        placeholder={t("urlPlaceholder")}
        className="h-7 text-sm"
        aria-label={t("urlLabel")}
      />
      <div className="flex gap-2">
        <Button
          variant="outline"
          size="sm"
          className="h-7 text-xs"
          onClick={handleSubmit}
          disabled={!canSubmit}
        >
          {submitting && <Loader2 className="h-3 w-3 mr-1 animate-spin" />}
          {t("save")}
        </Button>
        <Button
          variant="ghost"
          size="sm"
          className="h-7 text-xs"
          onClick={onCancel}
          disabled={submitting}
        >
          {t("cancel")}
        </Button>
      </div>
    </div>
  );
}
