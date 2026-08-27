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
  Phone,
  CheckCircle2,
  Circle,
  BedDouble,
  Mountain,
  TreePine,
  type LucideIcon,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import type { AccommodationData } from "@btp/core";
import { formatPrice, formatDistanceKm } from "@/lib/formatters";
import {
  ACCOMMODATION_TYPES,
  accommodationTypeLabelKey,
  isAccommodationType,
  type AccommodationType,
} from "@/lib/accommodation-types";
import {
  externalUrlHostname,
  normalizeExternalUrl,
} from "@/lib/validation/url";

/**
 * One icon per accommodation family (roof, bed, tent, hut).
 * `Record<AccommodationType, …>` is exhaustive on purpose: adding a type to
 * ACCOMMODATION_TYPES without an icon here is a TypeScript error, instead of a
 * silent fallback to the generic MapPin.
 */
export const ACCOMMODATION_TYPE_ICONS: Record<AccommodationType, LucideIcon> = {
  hotel: Hotel,
  hostel: BedDouble,
  camp_site: Tent,
  chalet: Home,
  guest_house: BedDouble,
  alpine_hut: Mountain,
  wilderness_hut: TreePine,
  other: MapPin,
};

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

  const TypeIcon = isAccommodationType(accommodation.type)
    ? ACCOMMODATION_TYPE_ICONS[accommodation.type]
    : ACCOMMODATION_TYPE_ICONS.other;
  const typeLabel = t(accommodationTypeLabelKey(accommodation.type));
  const distLabel = formatDistanceKm(accommodation.distanceToEndPoint ?? 0);
  // OSM `website` tags and user input are unvalidated: normalize before render,
  // and drop the link entirely when the value is not a usable http(s) URL.
  const websiteHref = normalizeExternalUrl(accommodation.url);
  const websiteHostname = externalUrlHostname(accommodation.url);
  const wikipediaHref = normalizeExternalUrl(accommodation.wikipediaUrl);
  // Both halves of the OSM primary key are needed to address the object; an
  // entry the rider typed in, or one coming from DataTourisme, has neither.
  const osmHref =
    accommodation.osmType && accommodation.osmId
      ? `https://www.openstreetmap.org/${accommodation.osmType}/${accommodation.osmId}`
      : null;

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
      url: normalizeExternalUrl(editUrl),
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
            {ACCOMMODATION_TYPES.map((type) => (
              <option key={type} value={type}>
                {t(accommodationTypeLabelKey(type))}
              </option>
            ))}
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
        {websiteHref && (
          <a
            href={websiteHref}
            target="_blank"
            rel="noopener noreferrer"
            className="text-sm text-muted-foreground font-normal flex items-center gap-0.5 hover:underline"
            data-testid="accommodation-website-link"
          >
            {websiteHostname}
            <ExternalLink className="h-3 w-3" />
          </a>
        )}
      </div>

      {/* Wikidata thumbnail — https-only (mirrors poi-popover), with fixed
          dimensions to avoid layout shift and a hide-on-error fallback. */}
      {accommodation.imageUrl?.startsWith("https://") && (
        <div className="mt-2">
          <img
            src={accommodation.imageUrl}
            alt={accommodation.name}
            loading="lazy"
            width={180}
            height={120}
            onError={(e) => {
              e.currentTarget.style.display = "none";
            }}
            className="rounded aspect-[3/2] object-cover w-full max-w-[180px]"
          />
        </div>
      )}

      {/* Type icon + label + price + distance to end point */}
      <div className="flex items-center gap-3 mt-1 text-sm text-muted-foreground flex-wrap">
        <div className="flex items-center gap-1.5">
          <TypeIcon
            className="h-3.5 w-3.5"
            data-testid="accommodation-type-icon"
          />
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
        {accommodation.phone && (
          <a
            href={`tel:${accommodation.phone}`}
            className="flex items-center gap-1 hover:underline"
            data-testid="accommodation-phone-link"
          >
            <Phone className="h-3.5 w-3.5" />
            <span>{accommodation.phone}</span>
          </a>
        )}
        {accommodation.source && accommodation.source !== "osm" && (
          <span
            className="inline-flex items-center text-[10px] font-medium uppercase tracking-wide text-muted-foreground bg-muted rounded px-1.5 py-0.5"
            data-testid="accommodation-source-badge"
          >
            {accommodation.source === "datatourisme"
              ? "DataTourisme"
              : accommodation.source === "manual"
                ? t("sourceManual")
                : accommodation.source}
          </span>
        )}
      </div>

      {/* Postal address (manual entries carry it; OSM/DataTourisme may too) */}
      {accommodation.address && (
        <div
          className="mt-1 flex items-center gap-1 text-sm text-muted-foreground"
          data-testid="accommodation-address"
        >
          <MapPin className="h-3.5 w-3.5 shrink-0" />
          <span>{accommodation.address}</span>
        </div>
      )}

      {/* Wikipedia + OpenStreetMap links */}
      {(wikipediaHref || osmHref) && (
        <div className="mt-1 flex items-center gap-3 flex-wrap">
          {wikipediaHref && (
            <a
              href={wikipediaHref}
              target="_blank"
              rel="noopener noreferrer"
              className="text-xs text-primary flex items-center gap-0.5 hover:underline"
            >
              <ExternalLink className="h-3 w-3" />
              {t("see_on_wikipedia")}
            </a>
          )}
          {osmHref && (
            <a
              href={osmHref}
              target="_blank"
              rel="noopener noreferrer"
              className="text-xs text-primary flex items-center gap-0.5 hover:underline"
              data-testid="accommodation-osm-link"
            >
              <ExternalLink className="h-3 w-3" />
              {t("see_on_osm")}
            </a>
          )}
        </div>
      )}
    </div>
  );
}
