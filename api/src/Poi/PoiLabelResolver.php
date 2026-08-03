<?php

declare(strict_types=1);

namespace App\Poi;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Localised label for a POI category, used as the display name of a POI whose
 * source carries none.
 *
 * A raw category is an OSM tag value ("supermarket", "monument"), so surfacing
 * it as a name leaks an English slug into a localised UI. Resolution happens
 * server-side because alert messages are pre-formatted before publication.
 *
 * The sources deliberately keep `name` null instead of falling back to the
 * category: a shared generic label makes every anonymous POI of a category look
 * like the same place to {@see \App\Geo\NearbyNameDeduplicator}, which then
 * drops all but one of them.
 */
final readonly class PoiLabelResolver
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * Inline label for an alert sentence ("… (château, 250m du tracé)"). An
     * unmapped category falls back to a generic wording rather than leaking the
     * tag into a translated message.
     */
    public function label(string $category, string $locale): string
    {
        $key = 'poi_type.'.$category;
        $label = $this->translator->trans($key, [], 'alerts', $locale);

        return $key === $label ? $this->translator->trans('poi_type.unknown', [], 'alerts', $locale) : $label;
    }

    /**
     * Same label, capitalised for use as a standalone POI name.
     */
    public function displayName(string $category, string $locale): string
    {
        return mb_ucfirst($this->label($category, $locale));
    }
}
