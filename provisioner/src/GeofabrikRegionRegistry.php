<?php

declare(strict_types=1);

namespace Provisioner;

final class GeofabrikRegionRegistry
{
    /**
     * @return array<string, array{slug: string, size: string}>
     */
    public static function all(): array
    {
        return [
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
        return \sprintf(
            'https://download.geofabrik.de/europe/france/%s-latest.osm.pbf',
            $slug,
        );
    }
}
