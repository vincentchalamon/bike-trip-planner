"use client";

import { useEffect, useId, useState } from "react";
import { useTranslations } from "next-intl";
import { Loader2, Sparkles, Trash2 } from "lucide-react";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { toast } from "@/components/ui/sonner";
import { cn } from "@/lib/utils";
import {
  AI_PROVIDERS,
  clearAiSettings,
  fetchAiSettings,
  localizedApiErrorMessage,
  saveAiSettings,
  type AiProvider,
} from "@/lib/api/client";
import { useUiStore } from "@/store/ui-store";

/**
 * "Assistant IA" account section (ADR-042): the bring-your-own-token AI
 * configuration. Picks a cloud provider + masked API key, then Save (PUT) /
 * Clear (DELETE) against `/users/me/ai-settings`. On load the current state is
 * read (selected provider + a "token configured" indicator — never the token
 * itself).
 *
 * 422 validation errors are surfaced inline; the `configured` capability flag
 * in {@link useUiStore} is kept in sync so the disabled-but-visible AI surfaces
 * react immediately to a save/clear without a reload.
 *
 * RGPD: trip data (route, towns, dates) is sent to the chosen provider with the
 * user's key — disclosed explicitly below the form.
 */
export function AiProviderSection() {
  const t = useTranslations("accountSettings.ai");
  const tErrors = useTranslations();
  const setAiConfigured = useUiStore((s) => s.setAiConfigured);
  const providerId = useId();
  const tokenId = useId();
  const errorId = useId();

  const [provider, setProvider] = useState<AiProvider | "">("");
  const [token, setToken] = useState("");
  const [tokenConfigured, setTokenConfigured] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [isClearing, setIsClearing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    void fetchAiSettings().then((settings) => {
      if (cancelled || !settings) return;
      setProvider(settings.provider ?? "");
      setTokenConfigured(settings.tokenConfigured);
    });
    return () => {
      cancelled = true;
    };
  }, []);

  async function handleSave() {
    if (!provider || token.trim().length === 0) return;
    setIsSaving(true);
    setError(null);
    try {
      const { data, error: apiError } = await saveAiSettings(provider, token);
      if (apiError) {
        setError(localizedApiErrorMessage(apiError, tErrors));
        return;
      }
      setProvider(data.provider ?? provider);
      setTokenConfigured(data.tokenConfigured);
      setToken("");
      setAiConfigured(Boolean(data.provider));
      toast.success(t("saved"));
    } catch {
      toast.error(t("saveFailed"));
    } finally {
      setIsSaving(false);
    }
  }

  async function handleClear() {
    setIsClearing(true);
    setError(null);
    try {
      const ok = await clearAiSettings();
      if (!ok) {
        toast.error(t("clearFailed"));
        return;
      }
      setProvider("");
      setToken("");
      setTokenConfigured(false);
      setAiConfigured(false);
      toast.success(t("cleared"));
    } catch {
      toast.error(t("clearFailed"));
    } finally {
      setIsClearing(false);
    }
  }

  const busy = isSaving || isClearing;
  const canSave = provider !== "" && token.trim().length > 0 && !busy;

  return (
    <Card data-testid="ai-provider-section" id="ai">
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <Sparkles className="h-5 w-5 text-brand" aria-hidden="true" />
          {t("title")}
        </CardTitle>
        <CardDescription>{t("description")}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <div className="flex flex-col gap-2">
          <label htmlFor={providerId} className="text-sm font-medium">
            {t("providerLabel")}
          </label>
          <select
            id={providerId}
            value={provider}
            onChange={(e) => setProvider(e.target.value as AiProvider | "")}
            disabled={busy}
            data-testid="ai-provider-select"
            className={cn(
              "w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs",
              "focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:border-ring",
              "disabled:cursor-not-allowed disabled:opacity-50",
            )}
          >
            <option value="">{t("providerPlaceholder")}</option>
            {AI_PROVIDERS.map((p) => (
              <option key={p} value={p}>
                {t(`providers.${p}`)}
              </option>
            ))}
          </select>
        </div>

        <div className="flex flex-col gap-2">
          <label htmlFor={tokenId} className="text-sm font-medium">
            {t("tokenLabel")}
          </label>
          <Input
            id={tokenId}
            type="password"
            autoComplete="off"
            value={token}
            onChange={(e) => {
              setToken(e.target.value);
              setError(null);
            }}
            placeholder={t("tokenPlaceholder")}
            disabled={busy}
            data-testid="ai-token-input"
            aria-invalid={!!error}
            aria-describedby={error ? errorId : undefined}
            className={cn(error && "ring-2 ring-destructive")}
          />
          <span
            className="text-xs text-muted-foreground"
            data-testid="ai-token-status"
          >
            {tokenConfigured ? t("tokenConfigured") : t("tokenNotConfigured")}
          </span>
          {error && (
            <p
              id={errorId}
              role="alert"
              className="text-sm text-destructive"
              data-testid="ai-settings-error"
            >
              {error}
            </p>
          )}
        </div>

        <p
          className="text-xs text-muted-foreground"
          data-testid="ai-settings-rgpd"
        >
          {t("rgpd")}
        </p>

        <div className="flex flex-wrap gap-2">
          <Button
            type="button"
            className="gap-2 cursor-pointer"
            onClick={() => void handleSave()}
            disabled={!canSave}
            data-testid="ai-settings-save"
          >
            {isSaving ? (
              <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
            ) : (
              <Sparkles className="h-4 w-4" aria-hidden="true" />
            )}
            {isSaving ? t("saving") : t("save")}
          </Button>
          <Button
            type="button"
            variant="outline"
            className="gap-2 cursor-pointer"
            onClick={() => void handleClear()}
            disabled={busy || (!tokenConfigured && provider === "")}
            data-testid="ai-settings-clear"
          >
            {isClearing ? (
              <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
            ) : (
              <Trash2 className="h-4 w-4" aria-hidden="true" />
            )}
            {isClearing ? t("clearing") : t("clear")}
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
