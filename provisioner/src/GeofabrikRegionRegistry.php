<?php

declare(strict_types=1);

namespace Provisioner;

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
     * @return array<string, array{slug: string, size: string}>
     */
    public static function all(): array
    {
        return [
            'France (entiere)' => ['slug' => 'france', 'size' => '4400 MB'],
            'Belgique' => ['slug' => 'belgium', 'size' => '500 MB'],
            'Pays-Bas' => ['slug' => 'netherlands', 'size' => '1600 MB'],
            'Luxembourg' => ['slug' => 'luxembourg', 'size' => '40 MB'],
            'Alsace' => ['slug' => 'alsace', 'size' => '122 MB'],
            'Aquitaine' => ['slug' => 'aquitaine', 'size' => '276 MB'],
            'Auvergne' => ['slug' => 'auvergne', 'size' => '141 MB'],
            'Basse-Normandie' => ['slug' => 'basse-normandie', 'size' => '134 MB'],
            'Bourgogne' => ['slug' => 'bourgogne', 'size' => '186 MB'],
            'Bretagne' => ['slug' => 'bretagne', 'size' => '307 MB'],
            'Centre' => ['slug' => 'centre', 'size' => '225 MB'],
            'Champagne-Ardenne' => ['slug' => 'champagne-ardenne', 'size' => '98 MB'],
            'Corse' => ['slug' => 'corse', 'size' => '32 MB'],
            'Franche-Comte' => ['slug' => 'franche-comte', 'size' => '115 MB'],
            'Guadeloupe' => ['slug' => 'guadeloupe', 'size' => '23 MB'],
            'Guyane' => ['slug' => 'guyane', 'size' => '14 MB'],
            'Haute-Normandie' => ['slug' => 'haute-normandie', 'size' => '99 MB'],
            'Ile-de-France' => ['slug' => 'ile-de-france', 'size' => '314 MB'],
            'Languedoc-Roussillon' => ['slug' => 'languedoc-roussillon', 'size' => '249 MB'],
            'Limousin' => ['slug' => 'limousin', 'size' => '92 MB'],
            'Lorraine' => ['slug' => 'lorraine', 'size' => '160 MB'],
            'Martinique' => ['slug' => 'martinique', 'size' => '19 MB'],
            'Mayotte' => ['slug' => 'mayotte', 'size' => '10 MB'],
            'Midi-Pyrenees' => ['slug' => 'midi-pyrenees', 'size' => '336 MB'],
            'Nord-Pas-de-Calais' => ['slug' => 'nord-pas-de-calais', 'size' => '223 MB'],
            'Pays-de-la-Loire' => ['slug' => 'pays-de-la-loire', 'size' => '347 MB'],
            'Picardie' => ['slug' => 'picardie', 'size' => '124 MB'],
            'Poitou-Charentes' => ['slug' => 'poitou-charentes', 'size' => '217 MB'],
            'Provence-Alpes-Cote-d-Azur' => ['slug' => 'provence-alpes-cote-d-azur', 'size' => '362 MB'],
            'Reunion' => ['slug' => 'reunion', 'size' => '32 MB'],
            'Rhone-Alpes' => ['slug' => 'rhone-alpes', 'size' => '491 MB'],
        ];
    }

    public static function downloadUrl(string $slug): string
    {
        // Whole countries (France + Benelux) live at the europe/ level; French
        // regions live one level down under europe/france/.
        if (\in_array($slug, self::EUROPE_LEVEL_SLUGS, true)) {
            return \sprintf('https://download.geofabrik.de/europe/%s-latest.osm.pbf', $slug);
        }

        return \sprintf(
            'https://download.geofabrik.de/europe/france/%s-latest.osm.pbf',
            $slug,
        );
    }
}
