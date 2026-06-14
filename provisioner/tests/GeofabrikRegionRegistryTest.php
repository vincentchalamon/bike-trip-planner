<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Provisioner\GeofabrikRegionRegistry;

final class GeofabrikRegionRegistryTest extends TestCase
{
    #[Test]
    public function allReturns31Regions(): void
    {
        self::assertCount(31, GeofabrikRegionRegistry::all());
    }

    #[Test]
    public function allIncludesBeneluxCountries(): void
    {
        $regions = GeofabrikRegionRegistry::all();
        self::assertArrayHasKey('Belgique', $regions);
        self::assertArrayHasKey('Pays-Bas', $regions);
        self::assertArrayHasKey('Luxembourg', $regions);
    }

    #[Test]
    public function downloadUrlForBeneluxUsesEuropeLevelExtract(): void
    {
        self::assertSame('https://download.geofabrik.de/europe/belgium-latest.osm.pbf', GeofabrikRegionRegistry::downloadUrl('belgium'));
        self::assertSame('https://download.geofabrik.de/europe/netherlands-latest.osm.pbf', GeofabrikRegionRegistry::downloadUrl('netherlands'));
        self::assertSame('https://download.geofabrik.de/europe/luxembourg-latest.osm.pbf', GeofabrikRegionRegistry::downloadUrl('luxembourg'));
    }

    #[Test]
    public function allIncludesWholeFrance(): void
    {
        self::assertArrayHasKey('France (entiere)', GeofabrikRegionRegistry::all());
    }

    #[Test]
    public function downloadUrlForWholeFranceUsesTopLevelExtract(): void
    {
        self::assertSame(
            'https://download.geofabrik.de/europe/france-latest.osm.pbf',
            GeofabrikRegionRegistry::downloadUrl('france'),
        );
    }

    #[Test]
    public function allRegionsHaveSlugAndSize(): void
    {
        foreach (GeofabrikRegionRegistry::all() as $name => $data) {
            self::assertArrayHasKey('slug', $data, \sprintf('Region "%s" missing slug', $name));
            self::assertArrayHasKey('size', $data, \sprintf('Region "%s" missing size', $name));
            self::assertNotEmpty($data['slug'], \sprintf('Region "%s" has empty slug', $name));
            self::assertMatchesRegularExpression(
                '/^\d+ MB$/',
                $data['size'],
                \sprintf('Region "%s" size format invalid: "%s"', $name, $data['size']),
            );
        }
    }

    #[Test]
    public function downloadUrlProducesValidGeofabrikUrl(): void
    {
        $url = GeofabrikRegionRegistry::downloadUrl('nord-pas-de-calais');

        self::assertSame(
            'https://download.geofabrik.de/europe/france/nord-pas-de-calais-latest.osm.pbf',
            $url,
        );
    }

    #[Test]
    public function allSlugsProduceValidUrls(): void
    {
        $europeLevel = ['france', 'belgium', 'netherlands', 'luxembourg'];

        foreach (GeofabrikRegionRegistry::all() as $name => $data) {
            $url = GeofabrikRegionRegistry::downloadUrl($data['slug']);
            if (\in_array($data['slug'], $europeLevel, true)) {
                // Whole countries live at the europe/ level, not under /france/.
                self::assertSame(
                    \sprintf('https://download.geofabrik.de/europe/%s-latest.osm.pbf', $data['slug']),
                    $url,
                    $name,
                );
            } else {
                self::assertStringStartsWith('https://download.geofabrik.de/europe/france/', $url, $name);
            }

            self::assertStringEndsWith('-latest.osm.pbf', $url, $name);
        }
    }
}
