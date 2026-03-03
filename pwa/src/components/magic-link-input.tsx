"use client";

import { useState, useCallback } from "react";
import { useTranslations } from "next-intl";
import { Check } from "lucide-react";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";

function isValidUrl(value: string): boolean {
  try {
    const url = new URL(value);
    return url.protocol === "https:" || url.protocol === "http:";
  } catch {
    return false;
  }
}

interface MagicLinkInputProps {
  onSubmit: (url: string) => Promise<void>;
  isProcessing: boolean;
  disabled?: boolean;
}

export function MagicLinkInput({
  onSubmit,
  isProcessing,
  disabled,
}: MagicLinkInputProps) {
  const t = useTranslations("magicLink");
  const [url, setUrl] = useState("");
  const [validationError, setValidationError] = useState<string | null>(null);

  const submit = useCallback(
    async (value: string) => {
      const trimmed = value.trim();
      if (!trimmed) return;

      if (!isValidUrl(trimmed)) {
        setValidationError(t("invalidUrl"));
        return;
      }

      setValidationError(null);
      await onSubmit(trimmed);
    },
    [onSubmit, t],
  );

  const handlePaste = useCallback(
    (e: React.ClipboardEvent<HTMLInputElement>) => {
      const pasted = e.clipboardData.getData("text");
      if (isValidUrl(pasted.trim())) {
        e.preventDefault();
        setUrl(pasted.trim());
        void submit(pasted.trim());
      }
    },
    [submit],
  );

  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent<HTMLInputElement>) => {
      if (e.key === "Enter") {
        e.preventDefault();
        void submit(url);
      }
    },
    [submit, url],
  );

  return (
    <div className="w-full">
      <div className="relative">
        <Input
          value={url}
          onChange={(e) => {
            setUrl(e.target.value);
            setValidationError(null);
          }}
          onPaste={handlePaste}
          onKeyDown={handleKeyDown}
          placeholder={t("placeholder")}
          disabled={disabled}
          className={cn(
            "w-full text-xl md:text-2xl bg-brand-light rounded-full border-none px-6 md:px-8 py-4 md:py-6 h-auto placeholder:text-muted-foreground/60",
            validationError && "ring-2 ring-destructive",
          )}
          data-testid="magic-link-input"
          aria-label={t("ariaLabel")}
        />
        {isProcessing && (
          <div className="absolute right-4 top-1/2 -translate-y-1/2">
            <Check className="h-5 w-5 text-green-500" />
          </div>
        )}
      </div>
      {validationError && (
        <p className="text-sm text-destructive mt-2 px-6">{validationError}</p>
      )}
    </div>
  );
}
