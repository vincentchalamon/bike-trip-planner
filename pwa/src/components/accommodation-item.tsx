"use client";

import { useState, useRef, useCallback, useEffect } from "react";
import { useTranslations } from "next-intl";
import {
  X,
  Pencil,
  Hotel,
  Home,
  Tent,
  MapPin,
  Euro,
  ExternalLink,
  Loader2,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import type { AccommodationData } from "@/lib/validation/schemas";

const typeIcons: Record<string, React.ElementType> = {
  hotel: Hotel,
  hostel: Home,
  chalet: Home,
  guest_house: Home,
  motel: Hotel,
  camp_site: Tent,
  alpine_hut: MapPin,
};

function formatPrice(acc: AccommodationData): string | null {
  const min = Number(acc.estimatedPriceMin);
  const max = Number(acc.estimatedPriceMax);

  if (isNaN(min) || isNaN(max) || (min === 0 && max === 0)) return null;

  const fmt = new Intl.NumberFormat(undefined, {
    style: "currency",
    currency: "EUR",
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  });

  if (acc.isExactPrice || min === max) {
    return fmt.format(max);
  }
  return `${fmt.format(min)} – ${fmt.format(max)}`;
}

interface ScrapedData {
  name: string | null;
  type: string | null;
  priceMin: number | null;
  priceMax: number | null;
}

async function scrapeUrl(url: string): Promise<ScrapedData | null> {
  const res = await fetch("/accommodations/scrape", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ url }),
  });
  if (!res.ok) return null;
  return res.json() as Promise<ScrapedData>;
}

function isValidUrl(value: string): boolean {
  try {
    const u = new URL(value);
    return u.protocol === "https:";
  } catch {
    return false;
  }
}

interface AccommodationItemProps {
  accommodation: AccommodationData;
  onUpdate: (data: Partial<AccommodationData>) => void;
  onRemove: () => void;
  initialEditing?: boolean;
}

export function AccommodationItem({
  accommodation,
  onUpdate,
  onRemove,
  initialEditing = false,
}: AccommodationItemProps) {
  const t = useTranslations("accommodation");
  const [editing, setEditing] = useState(initialEditing);
  const [editUrl, setEditUrl] = useState(accommodation.url ?? "");
  const [editName, setEditName] = useState(accommodation.name);
  const [editType, setEditType] = useState(accommodation.type);
  const [editPriceMin, setEditPriceMin] = useState(
    String(accommodation.estimatedPriceMin),
  );
  const [editPriceMax, setEditPriceMax] = useState(
    String(accommodation.estimatedPriceMax),
  );
  const [isScraping, setIsScraping] = useState(false);
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const urlInputRef = useRef<HTMLInputElement>(null);

  // Focus URL field when initially editing
  useEffect(() => {
    if (initialEditing && urlInputRef.current) {
      urlInputRef.current.focus();
    }
  }, [initialEditing]);

  const handleScrape = useCallback(async (url: string) => {
    if (!isValidUrl(url)) return;
    setIsScraping(true);
    try {
      const data = await scrapeUrl(url);
      if (data) {
        if (data.name) setEditName(data.name);
        if (data.type) setEditType(data.type);
        if (data.priceMin != null) setEditPriceMin(String(data.priceMin));
        if (data.priceMax != null) setEditPriceMax(String(data.priceMax));
      }
    } finally {
      setIsScraping(false);
    }
  }, []);

  function handleUrlChange(value: string) {
    setEditUrl(value);
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => {
      void handleScrape(value);
    }, 500);
  }

  const TypeIcon = typeIcons[accommodation.type] ?? MapPin;
  const typeLabel = t(`type_${accommodation.type}` as Parameters<typeof t>[0]);

  function startEditing() {
    setEditUrl(accommodation.url ?? "");
    setEditName(accommodation.name);
    setEditType(accommodation.type);
    setEditPriceMin(String(accommodation.estimatedPriceMin));
    setEditPriceMax(String(accommodation.estimatedPriceMax));
    setEditing(true);
  }

  function commitEdits() {
    onUpdate({
      name: editName,
      type: editType,
      estimatedPriceMin: parseFloat(editPriceMin) || 0,
      estimatedPriceMax: parseFloat(editPriceMax) || 0,
      url: editUrl || null,
    });
    setEditing(false);
  }

  function cancelEditing() {
    if (initialEditing) {
      onRemove();
    } else {
      setEditing(false);
    }
  }

  function handleKeyDown(e: React.KeyboardEvent) {
    if (e.key === "Enter") {
      commitEdits();
    } else if (e.key === "Escape") {
      cancelEditing();
    }
  }

  if (editing) {
    return (
      <div className="relative py-2 space-y-2">
        {/* URL field */}
        <div className="flex items-center gap-2 pr-8">
          <Input
            ref={urlInputRef}
            value={editUrl}
            onChange={(e) => handleUrlChange(e.target.value)}
            onKeyDown={handleKeyDown}
            placeholder={t("urlPlaceholder")}
            className="h-7 text-sm"
            aria-label={t("urlLabel")}
          />
          {isScraping && (
            <Loader2 className="h-4 w-4 animate-spin text-muted-foreground shrink-0" />
          )}
        </div>
        {/* Name */}
        <div className="flex items-center gap-2 pr-8">
          <Input
            value={editName}
            onChange={(e) => setEditName(e.target.value)}
            onKeyDown={handleKeyDown}
            placeholder={t("namePlaceholder")}
            className="h-7 text-sm"
            aria-label={t("nameLabel")}
          />
        </div>
        <div className="flex items-center gap-2">
          <select
            value={editType}
            onChange={(e) => setEditType(e.target.value)}
            className="h-7 text-sm rounded-md border border-input bg-transparent px-2"
            aria-label={t("typeLabel")}
          >
            <option value="hotel">{t("type_hotel")}</option>
            <option value="hostel">{t("type_hostel")}</option>
            <option value="camp_site">{t("type_camp_site")}</option>
            <option value="chalet">{t("type_chalet")}</option>
            <option value="guest_house">{t("type_guest_house")}</option>
            <option value="motel">{t("type_motel")}</option>
            <option value="alpine_hut">{t("type_alpine_hut")}</option>
            <option value="other">{t("type_other")}</option>
          </select>
          <div className="flex items-center gap-1">
            <Euro className="h-3.5 w-3.5 text-muted-icon" />
            <Input
              type="number"
              value={editPriceMin}
              onChange={(e) => setEditPriceMin(e.target.value)}
              onKeyDown={handleKeyDown}
              placeholder={t("priceMinPlaceholder")}
              className="h-7 w-16 text-sm"
              aria-label={t("priceMinLabel")}
            />
            <span className="text-muted-foreground">–</span>
            <Input
              type="number"
              value={editPriceMax}
              onChange={(e) => setEditPriceMax(e.target.value)}
              onKeyDown={handleKeyDown}
              placeholder={t("priceMaxPlaceholder")}
              className="h-7 w-16 text-sm"
              aria-label={t("priceMaxLabel")}
            />
          </div>
        </div>
        <div className="flex gap-2">
          <Button
            variant="outline"
            size="sm"
            className="h-7 text-xs"
            onClick={commitEdits}
          >
            {t("save")}
          </Button>
          <Button
            variant="ghost"
            size="sm"
            className="h-7 text-xs"
            onClick={cancelEditing}
          >
            {t("cancel")}
          </Button>
        </div>
      </div>
    );
  }

  return (
    <div className="relative group py-2">
      {/* Action buttons */}
      <div className="absolute top-2 right-0 flex gap-0.5">
        <Button
          variant="ghost"
          size="icon"
          className="h-6 w-6 text-muted-icon opacity-100 md:opacity-0 md:group-hover:opacity-100 transition-opacity cursor-pointer"
          onClick={startEditing}
          aria-label={t("edit")}
        >
          <Pencil className="h-3.5 w-3.5" />
        </Button>
        <Button
          variant="ghost"
          size="icon"
          className="h-6 w-6 text-muted-icon opacity-100 md:opacity-0 md:group-hover:opacity-100 transition-opacity cursor-pointer"
          onClick={onRemove}
          aria-label={t("remove")}
        >
          <X className="h-3.5 w-3.5" />
        </Button>
      </div>

      {/* Name + URL */}
      <div className="font-semibold text-sm pr-16 flex items-center gap-2">
        <span>{accommodation.name}</span>
        {accommodation.url && (
          <a
            href={accommodation.url}
            target="_blank"
            rel="noopener noreferrer"
            className="text-sm text-muted-foreground font-normal flex items-center gap-0.5 hover:underline"
          >
            {new URL(accommodation.url).hostname}
            <ExternalLink className="h-3 w-3" />
          </a>
        )}
      </div>

      {/* Type icon + label + price */}
      <div className="flex items-center gap-3 mt-1 text-sm text-muted-foreground">
        <div className="flex items-center gap-1.5">
          <TypeIcon className="h-3.5 w-3.5" />
          <span>{typeLabel}</span>
        </div>
        {formatPrice(accommodation) && (
          <div className="flex items-center gap-1">
            <Euro className="h-3.5 w-3.5" />
            <span>{formatPrice(accommodation)}</span>
          </div>
        )}
      </div>
    </div>
  );
}
