"use client";

import { useRef, useCallback } from "react";
import { useTranslations } from "next-intl";
import { Upload } from "lucide-react";
import { Button } from "@/components/ui/button";

interface GpxUploadButtonProps {
  onUpload: (file: File) => Promise<void>;
  disabled?: boolean;
}

export function GpxUploadButton({ onUpload, disabled }: GpxUploadButtonProps) {
  const t = useTranslations("gpxUpload");
  const inputRef = useRef<HTMLInputElement>(null);

  const handleClick = useCallback(() => {
    inputRef.current?.click();
  }, []);

  const handleChange = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      const file = e.target.files?.[0];
      if (file) {
        void onUpload(file);
      }
      // Reset input so the same file can be re-selected
      if (inputRef.current) {
        inputRef.current.value = "";
      }
    },
    [onUpload],
  );

  return (
    <>
      <input
        ref={inputRef}
        type="file"
        accept=".gpx"
        onChange={handleChange}
        className="hidden"
        data-testid="gpx-file-input"
        aria-label={t("ariaLabel")}
      />
      <Button
        variant="outline"
        size="icon"
        onClick={handleClick}
        disabled={disabled}
        className="shrink-0 h-auto py-4 px-4 md:py-6 md:px-6 rounded-full"
        aria-label={t("ariaLabel")}
        data-testid="gpx-upload-button"
      >
        <Upload className="h-5 w-5 md:h-6 md:w-6" />
      </Button>
    </>
  );
}
