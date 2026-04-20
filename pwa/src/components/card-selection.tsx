"use client";

import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type KeyboardEvent as ReactKeyboardEvent,
} from "react";
import { useTranslations } from "next-intl";
import { Link2, FileUp, Sparkles, ArrowLeft, Upload } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";
import { isSupportedSourceUrl, isValidUrl } from "@/lib/validation/url";

/**
 * Input mode selected by the user.
 *
 * - `null`: no selection yet, both cards displayed side-by-side
 * - `link`: Komoot/Strava/RideWithGPS URL input
 * - `gpx`: GPX file drag & drop + file picker
 */
export type InputMode = "link" | "gpx" | null;

interface CardSelectionProps {
  onSubmitUrl: (url: string) => Promise<void> | void;
  onUploadFile: (file: File) => Promise<void> | void;
  disabled?: boolean;
}

/** 30 MB — matches backend Caddy + PHP upload limit. */
const MAX_GPX_SIZE_BYTES = 30 * 1024 * 1024;

/**
 * Mutually-exclusive card selection for Act 1 "Préparation".
 *
 * Shows two active cards (Lien + GPX) and a placeholder for the upcoming
 * "Assistant IA" card. Selecting a card expands it and collapses the other,
 * revealing the appropriate input (URL field or drop zone).
 */
export function CardSelection({
  onSubmitUrl,
  onUploadFile,
  disabled = false,
}: CardSelectionProps) {
  const t = useTranslations("cardSelection");
  const [selected, setSelected] = useState<InputMode>(null);

  const handleSelect = useCallback(
    (mode: InputMode) => {
      if (disabled) return;
      setSelected(mode);
    },
    [disabled],
  );

  const handleBack = useCallback(() => {
    setSelected(null);
  }, []);

  return (
    <div
      className="w-full max-w-3xl flex flex-col items-center gap-6"
      data-testid="card-selection"
      data-selected={selected ?? ""}
    >
      <h2 className="text-lg md:text-xl font-semibold text-center">
        {t("heading")}
      </h2>

      <div
        className={cn(
          "grid w-full gap-4 transition-all",
          // When one card is selected it takes the full width; otherwise 3-up grid
          selected === null
            ? "grid-cols-1 sm:grid-cols-2 lg:grid-cols-3"
            : "grid-cols-1",
        )}
      >
        {/* Card: Link */}
        {(selected === null || selected === "link") && (
          <LinkCard
            expanded={selected === "link"}
            disabled={disabled}
            onSelect={() => handleSelect("link")}
            onSubmit={onSubmitUrl}
          />
        )}

        {/* Card: GPX */}
        {(selected === null || selected === "gpx") && (
          <GpxCard
            expanded={selected === "gpx"}
            disabled={disabled}
            onSelect={() => handleSelect("gpx")}
            onUpload={onUploadFile}
          />
        )}

        {/* Card: AI Assistant (coming soon placeholder) */}
        {selected === null && <AiCard />}
      </div>

      {selected !== null && (
        <Button
          variant="ghost"
          size="sm"
          onClick={handleBack}
          data-testid="card-selection-back"
          className="cursor-pointer"
        >
          <ArrowLeft className="h-4 w-4" aria-hidden="true" />
          {t("back")}
        </Button>
      )}
    </div>
  );
}

interface LinkCardProps {
  expanded: boolean;
  disabled: boolean;
  onSelect: () => void;
  onSubmit: (url: string) => Promise<void> | void;
}

function LinkCard({ expanded, disabled, onSelect, onSubmit }: LinkCardProps) {
  const t = useTranslations("cardSelection");
  const [url, setUrl] = useState("");
  const [error, setError] = useState<string | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  // Auto-focus the URL field when the card expands
  useEffect(() => {
    if (expanded) inputRef.current?.focus();
  }, [expanded]);

  const validate = useCallback(
    (value: string): string | null => {
      const trimmed = value.trim();
      if (!trimmed) return null;
      if (!isValidUrl(trimmed)) return t("linkInvalidUrl");
      if (!isSupportedSourceUrl(trimmed)) return t("linkUnsupportedSource");
      return null;
    },
    [t],
  );

  const submit = useCallback(
    async (value: string) => {
      const trimmed = value.trim();
      if (!trimmed) return;
      const validationError = validate(trimmed);
      if (validationError) {
        setError(validationError);
        return;
      }
      setError(null);
      await onSubmit(trimmed);
    },
    [onSubmit, validate],
  );

  const handleChange = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      setUrl(e.target.value);
      // Clear any previous error while the user is editing. Validation runs
      // on submit (Enter or paste of a fully-formed URL).
      setError(null);
    },
    [],
  );

  const handleKeyDown = useCallback(
    (e: ReactKeyboardEvent<HTMLInputElement>) => {
      if (e.key === "Enter") {
        e.preventDefault();
        void submit(url);
      }
    },
    [submit, url],
  );

  const handlePaste = useCallback(
    (e: React.ClipboardEvent<HTMLInputElement>) => {
      const pasted = e.clipboardData.getData("text");
      const trimmed = pasted.trim();
      if (!trimmed) return;
      if (isSupportedSourceUrl(trimmed)) {
        e.preventDefault();
        setUrl(trimmed);
        setError(null);
        void submit(trimmed);
      }
    },
    [submit],
  );

  return (
    <CardShell
      testId="card-link"
      ariaLabel={t("ariaSelectLink")}
      expanded={expanded}
      disabled={disabled}
      onSelect={onSelect}
      icon={<Link2 className="h-6 w-6" aria-hidden="true" />}
      title={t("linkTitle")}
      description={t("linkDescription")}
    >
      {expanded && (
        <div className="flex flex-col gap-2 w-full">
          <label
            htmlFor="card-link-url"
            className="text-sm font-medium text-foreground"
          >
            {t("linkInputLabel")}
          </label>
          <Input
            ref={inputRef}
            id="card-link-url"
            type="url"
            value={url}
            onChange={handleChange}
            onKeyDown={handleKeyDown}
            onPaste={handlePaste}
            placeholder={t("linkInputPlaceholder")}
            disabled={disabled}
            className={cn(
              "w-full text-base rounded-lg px-4 py-3 h-auto",
              error && "ring-2 ring-destructive",
            )}
            data-testid="magic-link-input"
            aria-invalid={!!error}
            aria-describedby={error ? "card-link-error" : undefined}
          />
          {error && (
            <p
              id="card-link-error"
              className="text-sm text-destructive"
              data-testid="card-link-error"
              role="alert"
            >
              {error}
            </p>
          )}
        </div>
      )}
    </CardShell>
  );
}

interface GpxCardProps {
  expanded: boolean;
  disabled: boolean;
  onSelect: () => void;
  onUpload: (file: File) => Promise<void> | void;
}

function GpxCard({ expanded, disabled, onSelect, onUpload }: GpxCardProps) {
  const t = useTranslations("cardSelection");
  const [isDragOver, setIsDragOver] = useState(false);
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const dropZoneRef = useRef<HTMLDivElement>(null);

  // Auto-focus the drop zone when the card expands
  useEffect(() => {
    if (expanded) dropZoneRef.current?.focus();
  }, [expanded]);

  const handleFile = useCallback(
    async (file: File) => {
      setSelectedFile(file);
      await onUpload(file);
    },
    [onUpload],
  );

  const handleBrowse = useCallback(() => {
    fileInputRef.current?.click();
  }, []);

  const handleInputChange = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      const file = e.target.files?.[0];
      if (file) void handleFile(file);
      if (fileInputRef.current) fileInputRef.current.value = "";
    },
    [handleFile],
  );

  const handleDragOver = useCallback(
    (e: React.DragEvent<HTMLDivElement>) => {
      e.preventDefault();
      if (disabled) return;
      if (e.dataTransfer) e.dataTransfer.dropEffect = "copy";
      setIsDragOver(true);
    },
    [disabled],
  );

  const handleDragLeave = useCallback((e: React.DragEvent<HTMLDivElement>) => {
    e.preventDefault();
    setIsDragOver(false);
  }, []);

  const handleDrop = useCallback(
    (e: React.DragEvent<HTMLDivElement>) => {
      e.preventDefault();
      setIsDragOver(false);
      if (disabled) return;
      const file = e.dataTransfer?.files[0];
      if (!file) return;
      if (!file.name.toLowerCase().endsWith(".gpx")) return;
      void handleFile(file);
    },
    [disabled, handleFile],
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

  const fileSizeMb = selectedFile
    ? (selectedFile.size / (1024 * 1024)).toFixed(2)
    : null;

  return (
    <CardShell
      testId="card-gpx"
      ariaLabel={t("ariaSelectGpx")}
      expanded={expanded}
      disabled={disabled}
      onSelect={onSelect}
      icon={<FileUp className="h-6 w-6" aria-hidden="true" />}
      title={t("gpxTitle")}
      description={t("gpxDescription")}
    >
      {expanded && (
        <div className="flex flex-col gap-3 w-full">
          <div
            ref={dropZoneRef}
            role="button"
            tabIndex={0}
            aria-label={t("gpxDescription")}
            onClick={handleBrowse}
            onKeyDown={handleKeyDown}
            onDragOver={handleDragOver}
            onDragLeave={handleDragLeave}
            onDrop={handleDrop}
            className={cn(
              "flex flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed px-4 py-8 cursor-pointer transition-colors",
              "focus:outline-none focus-visible:ring-2 focus-visible:ring-brand",
              isDragOver
                ? "border-brand bg-brand/5"
                : "border-muted-foreground/30 hover:border-brand/60 hover:bg-muted/20",
              disabled && "cursor-not-allowed opacity-60",
            )}
            data-testid="card-gpx-dropzone"
            data-drag-over={isDragOver}
          >
            <Upload
              className="h-8 w-8 text-muted-foreground"
              aria-hidden="true"
            />
            <p className="text-sm text-muted-foreground text-center">
              {t("gpxDescription")}
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
              data-testid="card-gpx-browse"
            >
              {t("gpxBrowse")}
            </Button>
            <p className="text-xs text-muted-foreground/70">
              {t("gpxSizeLimit")}
            </p>
          </div>

          <input
            ref={fileInputRef}
            type="file"
            accept=".gpx"
            onChange={handleInputChange}
            disabled={disabled}
            className="hidden"
            data-testid="gpx-file-input"
            aria-label={t("gpxTitle")}
          />

          {selectedFile && (
            <div
              className="flex items-center justify-between rounded-md border bg-muted/30 px-3 py-2 text-sm"
              data-testid="card-gpx-file-feedback"
            >
              <span
                className="truncate font-medium"
                data-testid="card-gpx-file-name"
              >
                {selectedFile.name}
              </span>
              <span
                className="text-muted-foreground shrink-0 ml-3"
                data-testid="card-gpx-file-size"
              >
                {t("gpxFileSize", { size: fileSizeMb ?? "0" })}
              </span>
            </div>
          )}

          {selectedFile && selectedFile.size > MAX_GPX_SIZE_BYTES && (
            <p className="text-sm text-destructive" role="alert">
              {t("gpxSizeLimit")}
            </p>
          )}
        </div>
      )}
    </CardShell>
  );
}

function AiCard() {
  const t = useTranslations("cardSelection");
  return (
    <CardShell
      testId="card-ai"
      ariaLabel={t("ariaSelectAi")}
      expanded={false}
      disabled
      onSelect={() => {}}
      icon={<Sparkles className="h-6 w-6" aria-hidden="true" />}
      title={t("aiTitle")}
      description={t("aiDescription")}
      badge={t("aiSoon")}
    />
  );
}

interface CardShellProps {
  testId: string;
  ariaLabel: string;
  expanded: boolean;
  disabled: boolean;
  onSelect: () => void;
  icon: React.ReactNode;
  title: string;
  description: string;
  badge?: string;
  children?: React.ReactNode;
}

function CardShell({
  testId,
  ariaLabel,
  expanded,
  disabled,
  onSelect,
  icon,
  title,
  description,
  badge,
  children,
}: CardShellProps) {
  const interactive = !expanded && !disabled;

  return (
    <div
      role={interactive ? "button" : undefined}
      tabIndex={interactive ? 0 : undefined}
      aria-label={interactive ? ariaLabel : undefined}
      aria-pressed={expanded ? true : undefined}
      aria-disabled={disabled || undefined}
      onClick={interactive ? onSelect : undefined}
      onKeyDown={
        interactive
          ? (e) => {
              if (e.key === "Enter" || e.key === " ") {
                e.preventDefault();
                onSelect();
              }
            }
          : undefined
      }
      data-testid={testId}
      data-expanded={expanded}
      data-disabled={disabled || undefined}
      className={cn(
        "bg-card text-card-foreground flex flex-col gap-3 rounded-xl border p-6 shadow-sm transition-all",
        interactive &&
          "cursor-pointer hover:border-brand hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand",
        expanded && "border-brand",
        disabled && "opacity-60 cursor-not-allowed",
      )}
    >
      <div className="flex items-start gap-3">
        <div
          className={cn(
            "flex items-center justify-center w-10 h-10 rounded-full bg-brand-light text-brand shrink-0",
            disabled && "bg-muted text-muted-foreground",
          )}
        >
          {icon}
        </div>
        <div className="flex flex-col gap-1 min-w-0 flex-1">
          <div className="flex items-center gap-2 flex-wrap">
            <h3 className="font-semibold leading-tight">{title}</h3>
            {badge && (
              <Badge variant="secondary" className="text-xs">
                {badge}
              </Badge>
            )}
          </div>
          <p className="text-sm text-muted-foreground">{description}</p>
        </div>
      </div>

      {children && <div className="mt-2">{children}</div>}
    </div>
  );
}
