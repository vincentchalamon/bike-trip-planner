"use client";

import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type KeyboardEvent as ReactKeyboardEvent,
} from "react";
import { useTranslations } from "next-intl";
import { Link2, FileUp, Sparkles, ArrowLeft } from "lucide-react";
import { AiChatCard } from "@/components/ai-chat-card";
import { AiUnavailableNotice } from "@/components/ai-unavailable-notice";
import { GpxDropZoneCard } from "@/components/gpx-drop-zone-card";
import { SourceUrlChip } from "@/components/source-url-chip";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";
import { isAiFeatureEnabled } from "@/lib/constants";
import { isSupportedSourceUrl, isValidUrl } from "@/lib/validation/url";
import { useUiStore } from "@/store/ui-store";

/**
 * Input mode selected by the user.
 *
 * - `null`: no selection yet, all three cards displayed side-by-side
 * - `link`: Komoot/Strava/RideWithGPS URL input
 * - `gpx`: GPX file drag & drop + file picker
 * - `ai`: free-form natural-language brief that drives AI route generation (ADR-042)
 */
export type InputMode = "link" | "gpx" | "ai" | null;

interface CardSelectionProps {
  onSubmitUrl: (url: string) => Promise<void> | void;
  onUploadFile: (file: File) => Promise<void> | void;
  /**
   * Fired when the rider launches AI route generation from the chat card's
   * "Lancer le calcul d'itinéraire" button. Receives the consolidated brief
   * (structured `collected` parameters + the rider's turns as fallback). The
   * trip-planner host forwards it to `POST /trips/ai-generate` (ADR-045). The
   * chat card also dispatches an `ai-chat-launch` DOM event for test/legacy
   * consumers.
   */
  onLaunchAiGeneration?: (brief: string) => void;
  disabled?: boolean;
}

/** 30 MB — matches backend Caddy + PHP upload limit. */
const MAX_GPX_SIZE_BYTES = 30 * 1024 * 1024;

/**
 * Mutually-exclusive card selection for Act 1 "Préparation".
 *
 * Shows three active cards (Lien + GPX + Assistant IA). Selecting a card
 * expands it and collapses the others, revealing the appropriate input
 * (URL field, drop zone or chat composer). The AI assistant card forwards a
 * natural-language brief to AI route generation (ADR-042).
 */
export function CardSelection({
  onSubmitUrl,
  onUploadFile,
  onLaunchAiGeneration,
  disabled = false,
}: CardSelectionProps) {
  const t = useTranslations("cardSelection");
  const aiCapability = useUiStore((s) => s.aiCapability);
  const aiEnabled = isAiFeatureEnabled();
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
            ? aiEnabled
              ? "grid-cols-1 sm:grid-cols-2 lg:grid-cols-3"
              : "grid-cols-1 sm:grid-cols-2"
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

        {/* Card: AI Assistant - natural-language brief to AI route generation
            (ADR-042). Hidden entirely while the AI feature is on hold behind
            `NEXT_PUBLIC_ENABLE_AI` (recette #649). When enabled it is always
            visible: disabled with an inline notice when the LLM tier is
            unreachable (#304), or disabled-but-visible with a "Configurez une
            IA" CTA when no provider is set on the account. */}
        {aiEnabled && (selected === null || selected === "ai") && (
          <AiCard
            expanded={selected === "ai"}
            disabled={disabled}
            unavailable={!aiCapability.available}
            notConfigured={!aiCapability.configured}
            onSelect={() => handleSelect("ai")}
            onLaunchGeneration={onLaunchAiGeneration}
          />
        )}
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

  const handleChange = useCallback((e: React.ChangeEvent<HTMLInputElement>) => {
    setUrl(e.target.value);
    // Clear any previous error while the user is editing. Validation runs
    // on submit (Enter or the Import button).
    setError(null);
  }, []);

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
        // Fill the field for review — submission stays explicit (Enter or the
        // Import button), so a paste never navigates away unprompted (#649).
        e.preventDefault();
        setUrl(trimmed);
        setError(null);
      }
    },
    [],
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
          <div className="flex gap-2">
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
            <Button
              type="button"
              onClick={() => void submit(url)}
              disabled={disabled || url.trim().length === 0}
              className="shrink-0 h-auto px-4 cursor-pointer bg-brand-fill text-white hover:bg-brand-fill-hover"
              data-testid="magic-link-submit"
            >
              {t("linkSubmit")}
            </Button>
          </div>
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
          {!error && url.trim().length > 0 && <SourceUrlChip value={url} />}
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
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [dropZoneState, setDropZoneState] = useState<
    | { status: "idle" }
    | { status: "uploading"; fileName: string; progress?: number | null }
    | { status: "error"; message: string }
  >({ status: "idle" });
  const dropZoneRef = useRef<HTMLDivElement>(null);

  // Auto-focus the drop zone when the card expands so keyboard users
  // don't have to tab again to reach it. Mirrors the LinkCard's
  // auto-focus on its URL input.
  useEffect(() => {
    if (expanded) dropZoneRef.current?.focus();
  }, [expanded]);

  const handleFile = useCallback(
    async (file: File) => {
      setSelectedFile(file);
      if (!file.name.toLowerCase().endsWith(".gpx")) {
        setDropZoneState({ status: "error", message: t("gpxInvalidType") });
        return;
      }
      if (file.size > MAX_GPX_SIZE_BYTES) {
        setDropZoneState({ status: "error", message: t("gpxFileTooLarge") });
        return;
      }
      setDropZoneState({ status: "uploading", fileName: file.name });
      try {
        await onUpload(file);
        // Caller is responsible for navigating away; if we're still mounted
        // reset the state to idle so the user can retry if needed.
        setDropZoneState({ status: "idle" });
      } catch (e) {
        setDropZoneState({
          status: "error",
          message:
            e instanceof Error && e.message
              ? e.message
              : t("gpxUploadGenericError"),
        });
      }
    },
    [onUpload, t],
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
          <GpxDropZoneCard
            ref={dropZoneRef}
            state={dropZoneState}
            disabled={disabled}
            maxBytes={MAX_GPX_SIZE_BYTES}
            onFileSelected={(file) => void handleFile(file)}
            dropZoneTestId="card-gpx-dropzone"
            fileInputTestId="gpx-file-input"
          />

          {selectedFile && dropZoneState.status !== "uploading" && (
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
        </div>
      )}
    </CardShell>
  );
}

interface AiCardProps {
  expanded: boolean;
  disabled: boolean;
  unavailable?: boolean;
  notConfigured?: boolean;
  onSelect: () => void;
  onLaunchGeneration?: (brief: string) => void;
}

function AiCard({
  expanded,
  disabled,
  unavailable = false,
  notConfigured = false,
  onSelect,
  onLaunchGeneration,
}: AiCardProps) {
  const t = useTranslations("cardSelection");

  return (
    <CardShell
      testId="card-ai"
      ariaLabel={t("ariaSelectAi")}
      expanded={expanded}
      disabled={disabled || unavailable || notConfigured}
      onSelect={onSelect}
      icon={<Sparkles className="h-6 w-6" aria-hidden="true" />}
      title={t("aiTitle")}
      description={t("aiDescription")}
    >
      {notConfigured ? (
        <AiUnavailableNotice variant="notConfigured" context="chat" />
      ) : unavailable ? (
        <AiUnavailableNotice context="chat" />
      ) : (
        expanded && (
          <AiChatCard
            onLaunchGeneration={onLaunchGeneration}
            disabled={disabled}
          />
        )
      )}
    </CardShell>
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
