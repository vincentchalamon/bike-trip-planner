"use client";

import {
  forwardRef,
  useCallback,
  useEffect,
  useRef,
  useState,
  type KeyboardEvent as ReactKeyboardEvent,
} from "react";
import { useTranslations } from "next-intl";
import { Upload, FileText, AlertCircle, Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

/**
 * Discrete states the drop zone can be in. Drives both visual styling and
 * accessibility annotations (aria-busy / aria-invalid). Using a discriminated
 * union avoids juggling several boolean flags ("isUploading", "hasError"…).
 *
 * The `"hovering"` visual state is intentionally NOT part of this public
 * union: it is purely a local, drag-event-driven display value (see
 * `EffectiveStatus` below). Exposing it would let callers force the UI into
 * a stuck "hovering" look that would never react to actual drag events.
 */
export type GpxDropZoneState =
  | { status: "idle" }
  | { status: "uploading"; fileName: string; progress?: number | null }
  | { status: "error"; message: string };

/**
 * Internal display value: the public statuses plus the locally-derived
 * `"hovering"` state. Kept private to this module on purpose.
 */
type EffectiveStatus = GpxDropZoneState["status"] | "hovering";

interface GpxDropZoneCardProps {
  /** External state if the parent wants to drive uploading/error UI. */
  state?: GpxDropZoneState;
  disabled?: boolean;
  /** Called with the selected or dropped file. Caller is responsible for type and size validation. */
  onFileSelected: (file: File) => void;
  /** Maximum file size in bytes — used only for the visual hint text. */
  maxBytes?: number;
  className?: string;
  /**
   * Optional override for the drop-zone test id. Defaults to
   * "gpx-drop-zone-card" but legacy callers may pin this to
   * "card-gpx-dropzone" to keep existing E2E selectors working.
   */
  dropZoneTestId?: string;
  /** Optional override for the hidden file input test id. */
  fileInputTestId?: string;
}

/**
 * Inline GPX drop zone with four explicit visual states (sprint 27, #402):
 * `idle`, `hovering`, `uploading`, `error`. Replaces the ad-hoc drop zone
 * UI that used to live inline in {@link CardSelection}'s GPX card. The
 * component is purely presentational for the upload/error states — the
 * parent decides when to switch into them via the `state` prop.
 *
 * The forwarded ref targets the inner role="button" drop zone div so
 * callers can programmatically focus it (e.g. when the parent card
 * expands).
 */
export const GpxDropZoneCard = forwardRef<HTMLDivElement, GpxDropZoneCardProps>(
  function GpxDropZoneCard(
    {
      state = { status: "idle" },
      disabled = false,
      onFileSelected,
      maxBytes,
      className,
      dropZoneTestId = "gpx-drop-zone-card",
      fileInputTestId = "gpx-drop-zone-input",
    },
    ref,
  ) {
    const t = useTranslations("gpxDropZone");
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [isDragOver, setIsDragOver] = useState(false);

    // Effective visual state: a local "hovering" overrides the parent-driven
    // "idle" state so we can show the hover styling without round-tripping.
    const effectiveStatus: EffectiveStatus =
      state.status === "idle" && isDragOver ? "hovering" : state.status;

    const handleBrowse = useCallback(() => {
      if (disabled || state.status === "uploading") return;
      fileInputRef.current?.click();
    }, [disabled, state.status]);

    const handleInputChange = useCallback(
      (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) onFileSelected(file);
        if (fileInputRef.current) fileInputRef.current.value = "";
      },
      [onFileSelected],
    );

    const handleDragOver = useCallback(
      (e: React.DragEvent<HTMLDivElement>) => {
        e.preventDefault();
        if (disabled || state.status === "uploading") return;
        if (e.dataTransfer) e.dataTransfer.dropEffect = "copy";
        setIsDragOver(true);
      },
      [disabled, state.status],
    );

    const handleDragLeave = useCallback(
      (e: React.DragEvent<HTMLDivElement>) => {
        e.preventDefault();
        if (e.currentTarget.contains(e.relatedTarget as Node | null)) return;
        setIsDragOver(false);
      },
      [],
    );

    const handleDrop = useCallback(
      (e: React.DragEvent<HTMLDivElement>) => {
        e.preventDefault();
        setIsDragOver(false);
        if (disabled || state.status === "uploading") return;
        const file = e.dataTransfer?.files[0];
        if (file) onFileSelected(file);
      },
      [disabled, state.status, onFileSelected],
    );

    const handleKeyDown = useCallback(
      (e: ReactKeyboardEvent<HTMLDivElement>) => {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          handleBrowse();
        }
      },
      [handleBrowse],
    );

    // Reset local hover state if the upload starts (e.g. via file picker)
    useEffect(() => {
      if (state.status === "uploading") setIsDragOver(false);
    }, [state.status]);

    const sizeHintMb = maxBytes
      ? Math.round(maxBytes / (1024 * 1024)).toString()
      : null;

    return (
      <div
        className={cn("flex flex-col gap-2", className)}
        data-testid={dropZoneTestId}
        data-status={effectiveStatus}
        data-drag-over={effectiveStatus === "hovering" ? true : undefined}
      >
        <div
          ref={ref}
          role="button"
          tabIndex={effectiveStatus === "uploading" || disabled ? -1 : 0}
          aria-label={t("ariaLabel")}
          aria-disabled={disabled || undefined}
          aria-busy={effectiveStatus === "uploading" || undefined}
          aria-invalid={effectiveStatus === "error" || undefined}
          onClick={handleBrowse}
          onKeyDown={handleKeyDown}
          onDragOver={handleDragOver}
          onDragLeave={handleDragLeave}
          onDrop={handleDrop}
          className={cn(
            "flex flex-col items-center justify-center gap-2 rounded-lg border-2 px-4 py-8 transition-colors text-center",
            "focus:outline-none focus-visible:ring-2 focus-visible:ring-brand",
            // Idle: dashed muted border
            effectiveStatus === "idle" &&
              "border-dashed border-muted-foreground/30 bg-background hover:border-brand/60 hover:bg-muted/20 cursor-pointer",
            // Hovering: solid brand border + soft accent fill
            effectiveStatus === "hovering" &&
              "border-solid border-brand bg-brand/5 cursor-copy",
            // Uploading: solid muted border, busy cursor
            effectiveStatus === "uploading" &&
              "border-solid border-muted-foreground/40 bg-muted/30 cursor-progress",
            // Error: solid red border + soft red fill
            effectiveStatus === "error" &&
              "border-solid border-destructive/70 bg-destructive/5 cursor-pointer",
            disabled && "cursor-not-allowed opacity-60",
          )}
        >
          {effectiveStatus === "uploading" ? (
            <Loader2
              className="h-8 w-8 text-brand animate-spin"
              aria-hidden="true"
            />
          ) : effectiveStatus === "error" ? (
            <AlertCircle
              className="h-8 w-8 text-destructive"
              aria-hidden="true"
            />
          ) : (
            <Upload
              className={cn(
                "h-8 w-8",
                effectiveStatus === "hovering"
                  ? "text-brand"
                  : "text-muted-foreground",
              )}
              aria-hidden="true"
            />
          )}

          {effectiveStatus === "uploading" && state.status === "uploading" ? (
            <div className="w-full max-w-xs flex flex-col gap-1.5 items-center">
              <div className="flex items-center gap-2 text-sm text-foreground max-w-full">
                <FileText className="h-4 w-4 shrink-0" aria-hidden="true" />
                <span
                  className="truncate font-medium"
                  data-testid="gpx-drop-zone-uploading-name"
                >
                  {state.fileName}
                </span>
              </div>
              <div
                className="w-full h-1.5 rounded-full bg-muted overflow-hidden"
                role="progressbar"
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={
                  typeof state.progress === "number"
                    ? Math.round(state.progress)
                    : undefined
                }
                aria-label={t("ariaProgress")}
              >
                <div
                  className={cn(
                    "h-full bg-brand transition-[width] duration-200",
                    typeof state.progress !== "number" &&
                      "animate-[indeterminate_1.5s_ease-in-out_infinite]",
                  )}
                  style={
                    typeof state.progress === "number"
                      ? {
                          width: `${Math.min(100, Math.max(0, state.progress))}%`,
                        }
                      : { width: "40%" }
                  }
                />
              </div>
              <p className="text-xs text-muted-foreground">{t("uploading")}</p>
            </div>
          ) : effectiveStatus === "error" && state.status === "error" ? (
            <>
              <p
                className="text-sm font-medium text-destructive"
                data-testid="gpx-drop-zone-error"
                role="alert"
              >
                {state.message}
              </p>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={(e) => {
                  e.stopPropagation();
                  handleBrowse();
                }}
                disabled={disabled}
                data-testid="gpx-drop-zone-retry"
              >
                {t("retry")}
              </Button>
            </>
          ) : (
            <>
              <p className="text-sm text-muted-foreground">
                {t(effectiveStatus === "hovering" ? "release" : "idle")}
              </p>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={(e) => {
                  e.stopPropagation();
                  handleBrowse();
                }}
                disabled={disabled}
                data-testid="gpx-drop-zone-browse"
              >
                {t("browse")}
              </Button>
              {sizeHintMb && (
                <p className="text-xs text-muted-foreground/70">
                  {t("sizeLimit", { size: sizeHintMb })}
                </p>
              )}
            </>
          )}
        </div>

        <input
          ref={fileInputRef}
          type="file"
          accept=".gpx"
          onChange={handleInputChange}
          disabled={disabled || effectiveStatus === "uploading"}
          className="hidden"
          data-testid={fileInputTestId}
          aria-label={t("ariaLabel")}
        />
      </div>
    );
  },
);
