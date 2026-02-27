"use client";

import { useState, useCallback } from "react";
import { Loader2 } from "lucide-react";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";

const SOURCE_URL_REGEX =
  /^https:\/\/(?:www\.komoot\.com\/.+|www\.google\.com\/maps\/d\/.+|maps\.app\.goo\.gl\/.+)/;

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
  const [url, setUrl] = useState("");
  const [validationError, setValidationError] = useState<string | null>(null);

  const submit = useCallback(
    async (value: string) => {
      const trimmed = value.trim();
      if (!trimmed) return;

      if (!SOURCE_URL_REGEX.test(trimmed)) {
        setValidationError("Please enter a valid Komoot or Google My Maps URL");
        return;
      }

      setValidationError(null);
      await onSubmit(trimmed);
    },
    [onSubmit],
  );

  const handlePaste = useCallback(
    (e: React.ClipboardEvent<HTMLInputElement>) => {
      const pasted = e.clipboardData.getData("text");
      if (SOURCE_URL_REGEX.test(pasted.trim())) {
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
          placeholder="Enter your Komoot link here..."
          disabled={disabled || isProcessing}
          className={cn(
            "w-full text-xl md:text-2xl bg-brand-light rounded-full border-none px-6 md:px-8 py-4 md:py-6 h-auto placeholder:text-muted-foreground/60",
            validationError && "ring-2 ring-destructive",
          )}
          data-testid="magic-link-input"
          aria-label="Komoot or Google My Maps URL"
        />
        {isProcessing && (
          <div className="absolute right-4 top-1/2 -translate-y-1/2">
            <Loader2 className="h-5 w-5 animate-spin text-brand" />
          </div>
        )}
      </div>
      {validationError && (
        <p className="text-sm text-destructive mt-2 px-6">{validationError}</p>
      )}
    </div>
  );
}
