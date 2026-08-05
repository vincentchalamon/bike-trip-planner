<?php

declare(strict_types=1);

namespace Provisioner;

final class GeofabrikRegionRegistry
{
    /**
     * Whole countries: Geofabrik publishes them at the `europe/` level rather
     * than under `europe/france/`, and they are also the **routing perimeter**
     * (#881) — the Valhalla graph is country-grained, built by
     * `make routing-build <slug>` from this list and extended country by
     * country. It is deliberately independent of the reference selection
     * (`.docker/osm/data/regions.json`), which is regional and changes often.
     *
     * `ROUTING_SLUGS` in compose.yaml's `valhalla-builder` mirrors this list:
     * extend both together.
     */
    private const array ROUTING_SLUGS = ['france', 'belgium', 'netherlands', 'luxembourg'];

    /**
     * @return list<string>
     */
    public static function routingSlugs(): array
    {
        return self::ROUTING_SLUGS;
    }

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
        if (\in_array($slug, self::ROUTING_SLUGS, true)) {
            return \sprintf('https://download.geofabrik.de/europe/%s-latest.osm.pbf', $slug);
        }

        return \sprintf(
            'https://download.geofabrik.de/europe/france/%s-latest.osm.pbf',
            $slug,
        );
    }
}
