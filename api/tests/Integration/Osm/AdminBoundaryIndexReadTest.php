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
 * seeds real PostGIS country multipolygons in osm.admin_boundaries and asserts the
 * ST_Covers point-in-country resolution plus the localized-name fallback chain
 * (name:<locale> → name:en → name) that replaces the Overpass is_in extraction.
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
}
