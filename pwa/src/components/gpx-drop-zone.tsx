"use client";

import { useState, useCallback, useEffect, type ReactNode } from "react";
import { useTranslations } from "next-intl";
import { Upload } from "lucide-react";

interface GpxDropZoneProps {
  onDrop: (file: File) => Promise<void>;
  disabled?: boolean;
  children: ReactNode;
}

export function GpxDropZone({ onDrop, disabled, children }: GpxDropZoneProps) {
  const t = useTranslations("gpxUpload");
  const [isDragging, setIsDragging] = useState(false);
  const [dragCounter, setDragCounter] = useState(0);

  const handleDragEnter = useCallback(
    (e: DragEvent) => {
      e.preventDefault();
      if (disabled) return;

      const hasFiles = e.dataTransfer?.types.includes("Files");
      if (!hasFiles) return;

      setDragCounter((c) => c + 1);
      setIsDragging(true);
    },
    [disabled],
  );

  const handleDragLeave = useCallback((e: DragEvent) => {
    e.preventDefault();
    setDragCounter((c) => {
      const next = c - 1;
      if (next <= 0) {
        setIsDragging(false);
        return 0;
      }
      return next;
    });
  }, []);

  const handleDragOver = useCallback((e: DragEvent) => {
    e.preventDefault();
    if (e.dataTransfer) {
      e.dataTransfer.dropEffect = "copy";
    }
  }, []);

  const handleDrop = useCallback(
    (e: DragEvent) => {
      e.preventDefault();
      setIsDragging(false);
      setDragCounter(0);

      if (disabled) return;

      const file = e.dataTransfer?.files[0];
      if (!file) return;

      const isGpx = file.name.toLowerCase().endsWith(".gpx");
      if (!isGpx) return;

      void onDrop(file);
    },
    [disabled, onDrop],
  );

  useEffect(() => {
    window.addEventListener("dragenter", handleDragEnter);
    window.addEventListener("dragleave", handleDragLeave);
    window.addEventListener("dragover", handleDragOver);
    window.addEventListener("drop", handleDrop);

    return () => {
      window.removeEventListener("dragenter", handleDragEnter);
      window.removeEventListener("dragleave", handleDragLeave);
      window.removeEventListener("dragover", handleDragOver);
      window.removeEventListener("drop", handleDrop);
    };
  }, [handleDragEnter, handleDragLeave, handleDragOver, handleDrop]);

  return (
    <div className="relative">
      {children}

      {isDragging && (
        <div className="fixed inset-0 z-50 flex items-end justify-center pb-8 pointer-events-none animate-in fade-in duration-200">
          {/* Translucent border overlay */}
          <div className="absolute inset-2 rounded-xl border-3 border-dashed border-primary/50 bg-primary/5" />

          {/* Bottom pill indicator */}
          <div className="relative flex items-center gap-3 bg-primary text-primary-foreground px-6 py-3 rounded-full shadow-lg">
            <Upload className="h-5 w-5" />
            <span className="text-sm font-medium">{t("dropZone")}</span>
          </div>
        </div>
      )}
    </div>
  );
}
