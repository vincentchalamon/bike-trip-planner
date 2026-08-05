<?php

declare(strict_types=1);

namespace Provisioner;

/**
 * The zones that may be opened, at the finest grain Geofabrik publishes (ADR-049 §1).
 *
 * `France (entiere)` is deliberately absent: a whole-country entry defeats "one zone
 * per run" — 4 400 MB re-imported to open one region — and it is the *routing* grain,
 * fed to `make routing-build france`, not the reference grain. Belgium, the
 * Netherlands and Luxembourg stay because Geofabrik publishes no finer extract for
 * them; they are a documented exception to the rule, not a breach of it.
 *
 * Each entry carries the `country` it belongs to, which is what the routing
 * containment check compares against the national slugs found in the routing volume
 * (ADR-049 §6): two explicit lists, neither inferred from a geometry.
 */
final class GeofabrikRegionRegistry
{
    /**
     * Whole countries: Geofabrik publishes them at the `europe/` level rather
     * than under `europe/france/`. This list exists for **URL resolution only**
     * (see {@see downloadUrl()}); it is not the routing perimeter.
     *
     * #881 briefly named it `ROUTING_SLUGS` to also mean "the countries the
     * Valhalla graph covers". That claim could not be enforced from here — the
     * perimeter of a build is the argument passed to `make routing-build <slug>`
     * — so it would have been a second source of truth that nothing checks. The
     * name is back to what the constant can actually guarantee.
     */
    private const array EUROPE_LEVEL_SLUGS = ['france', 'belgium', 'netherlands', 'luxembourg'];

    /**
     * @return array<string, array{slug: string, size: string, country: string}>
     */
    public static function all(): array
    {
        return [
            'Belgique' => ['slug' => 'belgium', 'size' => '500 MB', 'country' => 'belgium'],
            'Pays-Bas' => ['slug' => 'netherlands', 'size' => '1600 MB', 'country' => 'netherlands'],
            'Luxembourg' => ['slug' => 'luxembourg', 'size' => '40 MB', 'country' => 'luxembourg'],
            'Alsace' => ['slug' => 'alsace', 'size' => '122 MB', 'country' => 'france'],
            'Aquitaine' => ['slug' => 'aquitaine', 'size' => '276 MB', 'country' => 'france'],
            'Auvergne' => ['slug' => 'auvergne', 'size' => '141 MB', 'country' => 'france'],
            'Basse-Normandie' => ['slug' => 'basse-normandie', 'size' => '134 MB', 'country' => 'france'],
            'Bourgogne' => ['slug' => 'bourgogne', 'size' => '186 MB', 'country' => 'france'],
            'Bretagne' => ['slug' => 'bretagne', 'size' => '307 MB', 'country' => 'france'],
            'Centre' => ['slug' => 'centre', 'size' => '225 MB', 'country' => 'france'],
            'Champagne-Ardenne' => ['slug' => 'champagne-ardenne', 'size' => '98 MB', 'country' => 'france'],
            'Corse' => ['slug' => 'corse', 'size' => '32 MB', 'country' => 'france'],
            'Franche-Comte' => ['slug' => 'franche-comte', 'size' => '115 MB', 'country' => 'france'],
            'Guadeloupe' => ['slug' => 'guadeloupe', 'size' => '23 MB', 'country' => 'france'],
            'Guyane' => ['slug' => 'guyane', 'size' => '14 MB', 'country' => 'france'],
            'Haute-Normandie' => ['slug' => 'haute-normandie', 'size' => '99 MB', 'country' => 'france'],
            'Ile-de-France' => ['slug' => 'ile-de-france', 'size' => '314 MB', 'country' => 'france'],
            'Languedoc-Roussillon' => ['slug' => 'languedoc-roussillon', 'size' => '249 MB', 'country' => 'france'],
            'Limousin' => ['slug' => 'limousin', 'size' => '92 MB', 'country' => 'france'],
            'Lorraine' => ['slug' => 'lorraine', 'size' => '160 MB', 'country' => 'france'],
            'Martinique' => ['slug' => 'martinique', 'size' => '19 MB', 'country' => 'france'],
            'Mayotte' => ['slug' => 'mayotte', 'size' => '10 MB', 'country' => 'france'],
            'Midi-Pyrenees' => ['slug' => 'midi-pyrenees', 'size' => '336 MB', 'country' => 'france'],
            'Nord-Pas-de-Calais' => ['slug' => 'nord-pas-de-calais', 'size' => '223 MB', 'country' => 'france'],
            'Pays-de-la-Loire' => ['slug' => 'pays-de-la-loire', 'size' => '347 MB', 'country' => 'france'],
            'Picardie' => ['slug' => 'picardie', 'size' => '124 MB', 'country' => 'france'],
            'Poitou-Charentes' => ['slug' => 'poitou-charentes', 'size' => '217 MB', 'country' => 'france'],
            'Provence-Alpes-Cote-d-Azur' => ['slug' => 'provence-alpes-cote-d-azur', 'size' => '362 MB', 'country' => 'france'],
            'Reunion' => ['slug' => 'reunion', 'size' => '32 MB', 'country' => 'france'],
            'Rhone-Alpes' => ['slug' => 'rhone-alpes', 'size' => '491 MB', 'country' => 'france'],
        ];
    }

    /**
     * Resolves the zone argument of `make provision <zone>`, accepting either the
     * Geofabrik slug (what an operator copies from a runbook) or the display name.
     *
     * @return array{name: string, slug: string, size: string, country: string}|null null when the argument names no known zone
     */
    public static function resolve(string $zone): ?array
    {
        $needle = trim($zone);
        if ('' === $needle) {
            return null;
        }

        foreach (self::all() as $name => $region) {
            if ($needle === $region['slug'] || 0 === strcasecmp($needle, $name)) {
                return ['name' => $name, ...$region];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_values(array_column(self::all(), 'slug'));
    }

    public static function downloadUrl(string $slug): string
    {
        // Whole countries (Benelux) live at the europe/ level; French regions live
        // one level down under europe/france/.
        if (\in_array($slug, self::EUROPE_LEVEL_SLUGS, true)) {
            return \sprintf('https://download.geofabrik.de/europe/%s-latest.osm.pbf', $slug);
        }

        return \sprintf(
            'https://download.geofabrik.de/europe/france/%s-latest.osm.pbf',
            $slug,
        );
    }
}
