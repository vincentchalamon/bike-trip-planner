"use client";

import { useState, useRef, useEffect } from "react";
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
  CheckCircle2,
  Circle,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import type { AccommodationData } from "@/lib/validation/schemas";
import { formatPrice, formatDistanceKm } from "@/lib/formatters";

const typeIcons: Record<string, React.ElementType> = {
  hotel: Hotel,
  hostel: Home,
  chalet: Home,
  guest_house: Home,
  motel: Hotel,
  camp_site: Tent,
  alpine_hut: MapPin,
};

const typeLabelKeys = {
  hotel: "type_hotel",
  hostel: "type_hostel",
  camp_site: "type_camp_site",
  chalet: "type_chalet",
  guest_house: "type_guest_house",
  motel: "type_motel",
  alpine_hut: "type_alpine_hut",
  other: "type_other",
} as const;

interface AccommodationItemProps {
  accommodation: AccommodationData;
  onUpdate: (data: Partial<AccommodationData>) => void;
  onRemove: () => void;
  onSelect?: () => void;
  onDeselect?: () => void;
  isSelected?: boolean;
  initialEditing?: boolean;
  onHoverStart?: () => void;
  onHoverEnd?: () => void;
  /** Read-only surfaces (shared view): hide every mutating control. */
  readOnly?: boolean;
}

export function AccommodationItem({
  accommodation,
  onUpdate,
  onRemove,
  onSelect,
  onDeselect,
  isSelected = false,
  initialEditing = false,
  onHoverStart,
  onHoverEnd,
  readOnly = false,
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
  const urlInputRef = useRef<HTMLInputElement>(null);

  // Focus URL field when initially editing
  useEffect(() => {
    if (initialEditing && urlInputRef.current) {
      urlInputRef.current.focus();
    }
  }, [initialEditing]);

  const TypeIcon = typeIcons[accommodation.type] ?? MapPin;
  const typeKey =
    typeLabelKeys[accommodation.type as keyof typeof typeLabelKeys] ??
    "type_other";
  const typeLabel = t(typeKey);
  const distLabel = formatDistanceKm(accommodation.distanceToEndPoint ?? 0);

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
            onChange={(e) => setEditUrl(e.target.value)}
            onKeyDown={handleKeyDown}
            placeholder={t("urlPlaceholder")}
            className="h-7 text-sm"
            aria-label={t("urlLabel")}
          />
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
    <div
      className={`relative group py-2 ${isSelected ? "rounded-md bg-primary/5 border border-primary/20 px-2" : ""}`}
      data-testid="accommodation-item"
      onMouseEnter={onHoverStart}
      onMouseLeave={onHoverEnd}
    >
      {/* Action buttons — hidden on read-only surfaces (shared view) so the
          accommodation, including the selected one, cannot be re-selected,
          edited or removed. */}
      {!readOnly && (
        <div className="absolute top-2 right-0 flex gap-0.5">
          {(onSelect || onDeselect) && (
            <Button
              variant="ghost"
              size="icon"
              className={`h-6 w-6 transition-opacity cursor-pointer ${
                isSelected
                  ? "text-primary opacity-100"
                  : "text-muted-icon opacity-100 md:opacity-0 md:group-hover:opacity-100"
              }`}
              onClick={isSelected ? onDeselect : onSelect}
              aria-label={isSelected ? t("deselect") : t("select")}
              title={isSelected ? t("deselect") : t("selectTooltip")}
            >
              {isSelected ? (
                <CheckCircle2 className="h-3.5 w-3.5" />
              ) : (
                <Circle className="h-3.5 w-3.5" />
              )}
            </Button>
          )}
          <Button
            variant="ghost"
            size="icon"
            className="h-6 w-6 text-muted-icon opacity-100 md:opacity-0 md:group-hover:opacity-100 transition-opacity cursor-pointer"
            onClick={startEditing}
            aria-label={t("edit")}
            title={t("edit")}
          >
            <Pencil className="h-3.5 w-3.5" />
          </Button>
          <Button
            variant="ghost"
            size="icon"
            className="h-6 w-6 text-muted-icon opacity-100 md:opacity-0 md:group-hover:opacity-100 transition-opacity cursor-pointer"
            onClick={() => {
              if (isSelected) onDeselect?.();
              onRemove();
            }}
            aria-label={t("remove")}
            title={t("remove")}
          >
            <X className="h-3.5 w-3.5" />
          </Button>
        </div>
      )}

      {/* Name + URL */}
      <div className="font-semibold text-sm pr-16 flex items-center gap-2 flex-wrap">
        <span>{accommodation.name}</span>
        {isSelected && (
          <span className="inline-flex items-center gap-1 text-xs font-medium text-primary bg-primary/10 rounded-full px-2 py-0.5">
            <CheckCircle2 className="h-3 w-3" />
            {t("selected")}
          </span>
        )}
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

      {/* Wikidata thumbnail */}
      {accommodation.imageUrl && (
        <div className="mt-2">
          <img
            src={accommodation.imageUrl}
            alt={accommodation.name}
            loading="lazy"
            className="rounded aspect-[3/2] object-cover w-full max-w-[180px]"
          />
        </div>
      )}

      {/* Type icon + label + price + distance to end point */}
      <div className="flex items-center gap-3 mt-1 text-sm text-muted-foreground flex-wrap">
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
        {distLabel && (
          <div className="flex items-center gap-1">
            <MapPin className="h-3.5 w-3.5" />
            <span>{distLabel}</span>
          </div>
        )}
        {accommodation.source && accommodation.source !== "osm" && (
          <span className="inline-flex items-center text-[10px] font-medium uppercase tracking-wide text-muted-foreground bg-muted rounded px-1.5 py-0.5">
            {accommodation.source === "datatourisme"
              ? "DataTourisme"
              : accommodation.source}
          </span>
        )}
      </div>

      {/* Wikipedia link */}
      {accommodation.wikipediaUrl && (
        <div className="mt-1">
          <a
            href={accommodation.wikipediaUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="text-xs text-primary flex items-center gap-0.5 hover:underline"
          >
            <ExternalLink className="h-3 w-3" />
            Voir sur Wikipedia
          </a>
        </div>
      )}
    </div>
  );
}
