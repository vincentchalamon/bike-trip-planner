<?php

declare(strict_types=1);

namespace App\Tests\Integration\Osm;

use App\Osm\AdminBoundaryRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Integration coverage for the local-first admin-boundary read layer (ADR-040):
 * seeds real PostGIS multipolygons in osm.admin_boundaries and asserts the
 * ST_Covers resolution of the country (replacing the Overpass is_in extraction)
 * and of the locality (replacing the Nominatim reverse lookup, #880), plus the
 * localized-name fallback chain (name:<locale> → name:en → name).
 */
final class AdminBoundaryIndexReadTest extends KernelTestCase
{
    use ResetDatabase;

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->connection = $connection;

        $this->connection->executeStatement('TRUNCATE osm.admin_boundaries');

        // Three disjoint country squares: France (all names equal), Belgium (a
        // distinct name:fr), Luxembourg (only the plain name tag).
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO osm.admin_boundaries (osm_id, name, admin_level, tags, geom) VALUES
              (1, 'France', 2, '{"name":"France","name:en":"France","name:fr":"France"}'::jsonb,
                  ST_SetSRID(ST_GeomFromText('MULTIPOLYGON(((2 48, 3 48, 3 49, 2 49, 2 48)))'), 4326)),
              (2, 'Belgium', 2, '{"name":"Belgium","name:en":"Belgium","name:fr":"Belgique"}'::jsonb,
                  ST_SetSRID(ST_GeomFromText('MULTIPOLYGON(((4 50, 5 50, 5 51, 4 51, 4 50)))'), 4326)),
              (3, 'Luxembourg', 2, '{"name":"Luxembourg"}'::jsonb,
                  ST_SetSRID(ST_GeomFromText('MULTIPOLYGON(((6 49.5, 6.5 49.5, 6.5 50, 6 50, 6 49.5)))'), 4326))
            SQL);
    }

    #[Test]
    public function findCountryAtResolvesCountryContainingPoint(): void
    {
        $repository = new AdminBoundaryRepository($this->connection);

        self::assertSame('France', $repository->findCountryAt(48.5, 2.5, 'en'));
        self::assertSame('Belgium', $repository->findCountryAt(50.5, 4.5, 'en'));
    }

    #[Test]
    public function findCountryAtPrefersLocalizedName(): void
    {
        // name:fr is preferred over name:en when present.
        self::assertSame('Belgique', new AdminBoundaryRepository($this->connection)->findCountryAt(50.5, 4.5, 'fr'));
    }

    #[Test]
    public function findCountryAtFallsBackToNameEnThenName(): void
    {
        $repository = new AdminBoundaryRepository($this->connection);

        // No name:de → falls back to name:en.
        self::assertSame('Belgium', $repository->findCountryAt(50.5, 4.5, 'de'));
        // Only the plain name tag → falls back to it.
        self::assertSame('Luxembourg', $repository->findCountryAt(49.7, 6.2, 'fr'));
    }

    #[Test]
    public function findCountryAtReturnsNullOutsideAllBoundaries(): void
    {
        self::assertNull(new AdminBoundaryRepository($this->connection)->findCountryAt(0.0, 0.0, 'en'));
    }

    #[Test]
    public function findCountryAtIsDeterministicWhenBoundariesOverlap(): void
    {
        // Two admin_level=2 polygons covering the exact same area (a disputed
        // territory, or a point on a shared border where ST_Covers is true for
        // both): the lower osm_id must win, stably across plan/vacuum changes.
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO osm.admin_boundaries (osm_id, name, admin_level, tags, geom) VALUES
              (11, 'Beta', 2, '{"name":"Beta","name:en":"Beta"}'::jsonb,
                  ST_SetSRID(ST_GeomFromText('MULTIPOLYGON(((10 10, 12 10, 12 12, 10 12, 10 10)))'), 4326)),
              (10, 'Alpha', 2, '{"name":"Alpha","name:en":"Alpha"}'::jsonb,
                  ST_SetSRID(ST_GeomFromText('MULTIPOLYGON(((10 10, 12 10, 12 12, 10 12, 10 10)))'), 4326))
            SQL);

        self::assertSame('Alpha', new AdminBoundaryRepository($this->connection)->findCountryAt(11.0, 11.0, 'en'));
    }

    #[Test]
    public function findLocalityAtResolvesTheFinestCoveringBoundary(): void
    {
        $this->seedNestedFrenchBoundaries();
        $repository = new AdminBoundaryRepository($this->connection);

        // The department (6) and the commune (8) both cover the point: the commune
        // is the label a rider recognises, so the finest level must win.
        self::assertSame('Sarlat-la-Canéda', $repository->findLocalityAt(40.2, 10.2, 'fr'));
        self::assertSame('Douai', $repository->findLocalityAt(40.6, 10.6, 'fr'));
    }

    #[Test]
    public function findLocalityAtPrefersTheLocalizedName(): void
    {
        $this->seedNestedFrenchBoundaries();

        self::assertSame('Duacum', new AdminBoundaryRepository($this->connection)->findLocalityAt(40.6, 10.6, 'la'));
    }

    #[Test]
    public function findLocalityAtReturnsNullOutsideAnyMunicipalBoundary(): void
    {
        $this->seedNestedFrenchBoundaries();
        $repository = new AdminBoundaryRepository($this->connection);

        // Inside the country and the department, but in the fringe where the commune
        // polygon could not be built from a clipped extract: no locality, and the
        // department must not be passed off as one.
        self::assertNull($repository->findLocalityAt(40.9, 10.9, 'fr'));
        // Outside the provisioned zone entirely.
        self::assertNull($repository->findLocalityAt(0.0, 0.0, 'fr'));
    }

    #[Test]
    public function findCountryFallsBackToTheIsoCodeOfASubNationalBoundary(): void
    {
        // A clipped regional extract never imports the country relation, so only the
        // department is there: its ISO 3166-2 prefix still identifies the country.
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO osm.admin_boundaries (osm_id, name, admin_level, tags, geom) VALUES
              (100, 'Nord', 6, '{"name":"Nord","ISO3166-2":"FR-59"}'::jsonb,
                  ST_SetSRID(ST_GeomFromText('MULTIPOLYGON(((20 20, 22 20, 22 22, 20 22, 20 20)))'), 4326))
            SQL);
        $repository = new AdminBoundaryRepository($this->connection);

        self::assertSame('FR', $repository->findCountryCodeAt(21.0, 21.0));
        self::assertSame('France', $repository->findCountryAt(21.0, 21.0, 'fr'));
        self::assertSame('France', $repository->findCountryAt(21.0, 21.0, 'en'));
    }

    #[Test]
    public function findCountryPrefersTheCountryBoundaryOverTheIsoFallback(): void
    {
        // France (osm_id 1, seeded in setUp) covers 48.5/2.5; a department nested in
        // it must not shadow the country's own name and ISO 3166-1 code.
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO osm.admin_boundaries (osm_id, name, admin_level, tags, geom) VALUES
              (101, 'Seine-et-Marne', 6, '{"name":"Seine-et-Marne","ISO3166-2":"XX-77"}'::jsonb,
                  ST_SetSRID(ST_GeomFromText('MULTIPOLYGON(((2 48, 3 48, 3 49, 2 49, 2 48)))'), 4326))
            SQL);
        $this->connection->executeStatement("UPDATE osm.admin_boundaries SET tags = tags || '{\"ISO3166-1\":\"FR\"}'::jsonb WHERE osm_id = 1");
        $repository = new AdminBoundaryRepository($this->connection);

        self::assertSame('France', $repository->findCountryAt(48.5, 2.5, 'fr'));
        self::assertSame('FR', $repository->findCountryCodeAt(48.5, 2.5));
    }

    /**
     * A department (admin_level=6) with two communes (8) inside it, plus a fringe
     * area covered by the department only.
     */
    private function seedNestedFrenchBoundaries(): void
    {
        // WKT is (lon lat): the department spans lon 10..11 / lat 40..41.
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO osm.admin_boundaries (osm_id, name, admin_level, tags, geom) VALUES
              (200, 'Dordogne', 6, '{"name":"Dordogne","ISO3166-2":"FR-24"}'::jsonb,
                  ST_SetSRID(ST_GeomFromText('MULTIPOLYGON(((10 40, 11 40, 11 41, 10 41, 10 40)))'), 4326)),
              (201, 'Sarlat-la-Canéda', 8, '{"name":"Sarlat-la-Canéda"}'::jsonb,
                  ST_SetSRID(ST_GeomFromText('MULTIPOLYGON(((10.1 40.1, 10.4 40.1, 10.4 40.4, 10.1 40.4, 10.1 40.1)))'), 4326)),
              (202, 'Douai', 8, '{"name":"Douai","name:la":"Duacum","name:en":"Douai"}'::jsonb,
                  ST_SetSRID(ST_GeomFromText('MULTIPOLYGON(((10.5 40.5, 10.8 40.5, 10.8 40.8, 10.5 40.8, 10.5 40.5)))'), 4326))
            SQL);
    }
}
