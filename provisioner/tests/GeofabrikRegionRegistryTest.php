<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Provisioner\GeofabrikRegionRegistry;

final class GeofabrikRegionRegistryTest extends TestCase
{
    #[Test]
    public function allReturns30Zones(): void
    {
        self::assertCount(30, GeofabrikRegionRegistry::all());
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
    public function wholeFranceIsNotAnOpenableZone(): void
    {
        // ADR-049 §1: a whole-country entry defeats "one zone per run" — 4 400 MB
        // re-imported to open one region. France stays the *routing* grain, passed to
        // `make routing-build france`, and must not be selectable as reference data.
        self::assertArrayNotHasKey('France (entiere)', GeofabrikRegionRegistry::all());
        self::assertNotContains('france', GeofabrikRegionRegistry::slugs());
        self::assertNull(GeofabrikRegionRegistry::resolve('france'));
    }

    #[Test]
    public function downloadUrlStillResolvesTheFranceExtractForRouting(): void
    {
        // The URL resolver serves both grains; only the openable-zone list shrank.
        self::assertSame(
            'https://download.geofabrik.de/europe/france-latest.osm.pbf',
            GeofabrikRegionRegistry::downloadUrl('france'),
        );
    }

    #[Test]
    public function everyZoneHasSlugSizeAndCountry(): void
    {
        foreach (GeofabrikRegionRegistry::all() as $name => $data) {
            self::assertNotEmpty($data['slug'], \sprintf('Region "%s" has empty slug', $name));
            self::assertMatchesRegularExpression(
                '/^\d+ MB$/',
                $data['size'],
                \sprintf('Region "%s" size format invalid: "%s"', $name, $data['size']),
            );
            // The country is what the routing containment check compares against the
            // slugs found in the routing volume (ADR-049 §6), so a zone without one
            // could never be checked.
            self::assertMatchesRegularExpression(
                '/^[a-z]+$/',
                $data['country'],
                \sprintf('Region "%s" has no usable country slug', $name),
            );
        }
    }

    #[Test]
    public function frenchRegionsBelongToFranceAndBeneluxToThemselves(): void
    {
        $regions = GeofabrikRegionRegistry::all();

        self::assertSame('france', $regions['Bretagne']['country']);
        self::assertSame('france', $regions['Reunion']['country']);
        self::assertSame('belgium', $regions['Belgique']['country']);
        self::assertSame('luxembourg', $regions['Luxembourg']['country']);
    }

    #[Test]
    public function resolveAcceptsSlugAndDisplayName(): void
    {
        $bySlug = GeofabrikRegionRegistry::resolve('nord-pas-de-calais');
        self::assertNotNull($bySlug);
        self::assertSame('Nord-Pas-de-Calais', $bySlug['name']);
        self::assertSame('france', $bySlug['country']);

        $byName = GeofabrikRegionRegistry::resolve('Nord-Pas-de-Calais');
        self::assertSame($bySlug, $byName);

        // Case-insensitive on the name, and surrounding whitespace is not a typo worth
        // failing an operator's run for.
        self::assertSame($bySlug, GeofabrikRegionRegistry::resolve('  nord-pas-de-calais  '));
        self::assertSame($bySlug, GeofabrikRegionRegistry::resolve('NORD-PAS-DE-CALAIS'));
    }

    #[Test]
    public function resolveRejectsUnknownAndEmptyZones(): void
    {
        self::assertNull(GeofabrikRegionRegistry::resolve(''));
        self::assertNull(GeofabrikRegionRegistry::resolve('   '));
        self::assertNull(GeofabrikRegionRegistry::resolve('../../evil'));
        self::assertNull(GeofabrikRegionRegistry::resolve('atlantis'));
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
        // Independent oracle: do not read the class's own constant here, or the
        // assertion would only ever confirm itself.
        $europeLevel = ['belgium', 'netherlands', 'luxembourg'];

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
