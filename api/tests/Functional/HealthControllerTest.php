<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Health\RedisHealthClientFactory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HealthControllerTest extends ApiTestCase
{
    private Client $client;

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        self::$alwaysBootKernel = false;
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    #[\Override]
    protected function tearDown(): void
    {
        // Drop the kernel so the next test boots fresh with no leaked service
        // overrides (broken Postgres/Redis, mocked HTTP clients).
        self::ensureKernelShutdown();
        parent::tearDown();
    }

    #[Test]
    public function livenessReturns200(): void
    {
        $response = $this->client->request('GET', '/api/healthz');

        $this->assertResponseStatusCodeSame(200);

        $data = $response->toArray();
        $this->assertSame('ok', $data['status']);
        // The commit SHA is no longer exposed on the public endpoint (SEC-011).
        $this->assertArrayNotHasKey('commit', $data);
    }

    #[Test]
    public function livenessIsPubliclyAccessible(): void
    {
        // No Authorization header -> must still respond 200.
        $this->client->request('GET', '/api/healthz');

        $this->assertResponseStatusCodeSame(200);
    }

    #[Test]
    public function readinessReturns200WhenAllDependenciesAreUp(): void
    {
        $this->mockHealthHttpClients(
            valhalla: new MockResponse('OK', ['http_code' => 200]),
            mercure: new MockResponse('', ['http_code' => 200]),
        );

        $response = $this->client->request('GET', '/api/health');

        $this->assertResponseStatusCodeSame(200);

        $data = $response->toArray();
        $this->assertSame('ok', $data['status']);
        $this->assertArrayHasKey('deps', $data);
        foreach (['postgres', 'postgres_reference', 'redis', 'mercure', 'valhalla', 'reference_data'] as $dep) {
            $this->assertArrayHasKey($dep, $data['deps'], \sprintf('Missing dep %s', $dep));
            $this->assertArrayHasKey('status', $data['deps'][$dep]);
            $this->assertArrayHasKey('latency_ms', $data['deps'][$dep]);
        }
    }

    #[Test]
    public function readinessNeverProbesTheAiTier(): void
    {
        // AI is an optional per-user cloud provider (ADR-042), not a server
        // dependency — it must never appear in the readiness payload.
        $this->mockHealthHttpClients(
            valhalla: new MockResponse('OK', ['http_code' => 200]),
            mercure: new MockResponse('', ['http_code' => 200]),
        );

        $response = $this->client->request('GET', '/api/health');

        $this->assertResponseStatusCodeSame(200);
        $data = $response->toArray();
        $this->assertArrayNotHasKey('ollama_chat', $data['deps']);
        $this->assertArrayNotHasKey('ollama_analysis', $data['deps']);
    }

    #[Test]
    public function readinessReturns503WhenValhallaIsDown(): void
    {
        $this->mockHealthHttpClients(
            valhalla: new MockResponse('boom', ['http_code' => 500]),
            mercure: new MockResponse('', ['http_code' => 200]),
        );

        $response = $this->client->request('GET', '/api/health');

        $this->assertResponseStatusCodeSame(503);

        $data = $response->toArray(false);
        $this->assertSame('degraded', $data['status']);
        $this->assertSame('down', $data['deps']['valhalla']['status']);
    }

    #[Test]
    public function readinessReturns503WhenMercureIsUnreachable(): void
    {
        $this->mockHealthHttpClients(
            valhalla: new MockResponse('OK', ['http_code' => 200]),
            mercure: new MockResponse('', ['http_code' => 0, 'error' => 'connection refused']),
        );

        $response = $this->client->request('GET', '/api/health');

        $this->assertResponseStatusCodeSame(503);

        $data = $response->toArray(false);
        $this->assertSame('down', $data['deps']['mercure']['status']);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function readinessReturns503WhenPostgresIsDown(): void
    {
        $this->mockHealthHttpClients(
            valhalla: new MockResponse('OK', ['http_code' => 200]),
            mercure: new MockResponse('', ['http_code' => 200]),
        );

        // checkPostgres() runs `SET statement_timeout` (executeStatement) before
        // `SELECT 1` (executeQuery); when Postgres is unreachable the former
        // throws first, so both must fail to mirror the real failure mode.
        $brokenConnection = $this->createMock(Connection::class);
        $brokenConnection->method('executeStatement')->willThrowException(
            new \RuntimeException('connection refused')
        );
        $brokenConnection->method('executeQuery')->willThrowException(
            new \RuntimeException('connection refused')
        );
        self::getContainer()->set('doctrine.dbal.default_connection', $brokenConnection);

        $response = $this->client->request('GET', '/api/health');

        $this->assertResponseStatusCodeSame(503);

        $data = $response->toArray(false);
        $this->assertSame('down', $data['deps']['postgres']['status']);
        // Sanity: error string is sanitized (short class name, no stack trace).
        $this->assertArrayHasKey('error', $data['deps']['postgres']);
        $this->assertSame('RuntimeException', $data['deps']['postgres']['error']);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function readinessReportsReferencePostgresDownWithoutFlippingStatus(): void
    {
        // The shared read-only PG-référence (ADR-060) backs feature enrichment, not
        // the core trip flow, so it is non-required: an unreachable reference DB is
        // reported as down but never takes readiness below 200 (ADR-040).
        $this->mockHealthHttpClients(
            valhalla: new MockResponse('OK', ['http_code' => 200]),
            mercure: new MockResponse('', ['http_code' => 200]),
        );

        $brokenReference = $this->createMock(Connection::class);
        $brokenReference->method('executeStatement')->willThrowException(
            new \RuntimeException('connection refused')
        );
        $brokenReference->method('executeQuery')->willThrowException(
            new \RuntimeException('connection refused')
        );
        self::getContainer()->set('doctrine.dbal.reference_connection', $brokenReference);

        $response = $this->client->request('GET', '/api/health');

        $this->assertResponseStatusCodeSame(200);
        $data = $response->toArray();
        $this->assertSame('ok', $data['status']);
        $this->assertSame('down', $data['deps']['postgres_reference']['status']);
        $this->assertSame('RuntimeException', $data['deps']['postgres_reference']['error']);
        // The reference-data read runs on the same broken connection, so it is down too.
        $this->assertSame('down', $data['deps']['reference_data']['status']);
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function readinessReturns503WhenRedisIsDown(): void
    {
        $this->mockHealthHttpClients(
            valhalla: new MockResponse('OK', ['http_code' => 200]),
            mercure: new MockResponse('', ['http_code' => 200]),
        );

        // Swap the connection factory for one returning a \Redis whose ->ping()
        // throws, mirroring an unreachable Redis without mutating env vars.
        $brokenRedis = $this->createMock(\Redis::class);
        $brokenRedis->method('ping')->willThrowException(
            new \RuntimeException('connection refused')
        );
        $factory = $this->createMock(RedisHealthClientFactory::class);
        $factory->method('create')->willReturn($brokenRedis);
        self::getContainer()->set(RedisHealthClientFactory::class, $factory);

        $response = $this->client->request('GET', '/api/health');

        $this->assertResponseStatusCodeSame(503);
        $data = $response->toArray(false);
        $this->assertSame('down', $data['deps']['redis']['status']);
        $this->assertArrayHasKey('error', $data['deps']['redis']);
        $this->assertSame('RuntimeException', $data['deps']['redis']['error']);
    }

    #[Test]
    public function readinessReportsUnprovisionedReferenceDataWithoutFlippingStatus(): void
    {
        // An empty PostGIS index (no provisioning run yet) surfaces as down in
        // reference_data but is non-required, so the aggregate stays ok (ADR-040).
        $this->truncateProvisioningMetadata();
        $this->mockHealthHttpClients(
            valhalla: new MockResponse('OK', ['http_code' => 200]),
            mercure: new MockResponse('', ['http_code' => 200]),
        );

        $response = $this->client->request('GET', '/api/health');

        $this->assertResponseStatusCodeSame(200);
        $data = $response->toArray();
        $this->assertSame('ok', $data['status']);
        $this->assertArrayHasKey('reference_data', $data['deps']);
        $this->assertSame('down', $data['deps']['reference_data']['status']);
    }

    #[Test]
    public function readinessReportsReferenceDataFreshnessCountsAndCompleteness(): void
    {
        $this->truncateProvisioningMetadata();
        $connection = self::getContainer()->get('doctrine.dbal.reference_connection');
        \assert($connection instanceof Connection);
        $connection->executeStatement(<<<'SQL'
            INSERT INTO osm.metadata (refreshed_at, feature_counts, completeness, rejections)
            VALUES (
                now(),
                '{"pois": 12, "admin_boundaries": 4}'::jsonb,
                '{"pois": {"rows": 12, "named": 9, "named_ratio": 0.75},
                  "accommodations": {"rows": 4, "named": 1, "named_ratio": 0.25,
                                     "by_category": {"shelter": {"rows": 3, "named": 0, "named_ratio": 0.0}}}}'::jsonb,
                '{}'::jsonb
            )
            SQL);

        $this->mockHealthHttpClients(
            valhalla: new MockResponse('OK', ['http_code' => 200]),
            mercure: new MockResponse('', ['http_code' => 200]),
        );

        $response = $this->client->request('GET', '/api/health');

        $this->assertResponseStatusCodeSame(200);
        $data = $response->toArray();
        $reference = $data['deps']['reference_data'];
        $this->assertSame('ok', $reference['status']);
        $this->assertNotNull($reference['osm']);
        $this->assertIsString($reference['osm']['refreshed_at']);
        $this->assertLessThan(60, $reference['osm']['age_seconds']);
        $this->assertSame(12, $reference['osm']['feature_counts']['pois']);
        $this->assertSame(4, $reference['osm']['feature_counts']['admin_boundaries']);
        // Completeness ratios per table, plus the per-category breakdown that
        // arbitrates excluding unnamed accommodations (#877).
        $this->assertSame(0.75, $reference['osm']['completeness']['pois']['named_ratio']);
        $this->assertSame(0, $reference['osm']['completeness']['accommodations']['by_category']['shelter']['named']);
        $this->assertSame([], $reference['osm']['rejections']);
        $this->assertNull($reference['tourism'], 'tourism index still unprovisioned');
    }

    #[Test]
    public function readinessReportsTheReferenceIndexAgeWithoutAStalenessVerdict(): void
    {
        $this->truncateProvisioningMetadata();
        $connection = self::getContainer()->get('doctrine.dbal.reference_connection');
        \assert($connection instanceof Connection);
        // OSM refreshed 100 days ago. No scheduler refreshes these sources
        // (ADR-036) and obsolescence is assumed, so age carries no verdict: any
        // threshold would be permanently red, i.e. a dead signal.
        $connection->executeStatement(<<<'SQL'
            INSERT INTO osm.metadata (refreshed_at, feature_counts)
            VALUES (now() - interval '100 days', '{"pois": 12}'::jsonb)
            SQL);

        $this->mockHealthHttpClients(
            valhalla: new MockResponse('OK', ['http_code' => 200]),
            mercure: new MockResponse('', ['http_code' => 200]),
        );

        $response = $this->client->request('GET', '/api/health');

        $this->assertResponseStatusCodeSame(200);
        $data = $response->toArray();
        $this->assertSame('ok', $data['status'], 'reference_data is non-required');
        $reference = $data['deps']['reference_data'];
        $this->assertSame('ok', $reference['status'], 'a dated index is not a degraded one');
        $this->assertGreaterThan(99 * 86400, $reference['osm']['age_seconds']);
        $this->assertArrayNotHasKey('stale', $reference['osm']);
        $this->assertArrayNotHasKey('stale', $reference);
    }

    #[Test]
    public function readinessStillReportsAnIndexProvisionedBeforeTheCompletenessColumns(): void
    {
        // A metadata row written by the previous provisioner (counts only) must keep
        // reporting its counts, not read as unprovisioned.
        $this->truncateProvisioningMetadata();
        $connection = self::getContainer()->get('doctrine.dbal.reference_connection');
        \assert($connection instanceof Connection);
        $connection->executeStatement(<<<'SQL'
            INSERT INTO osm.metadata (refreshed_at, feature_counts)
            VALUES (now(), '{"pois": 12}'::jsonb)
            SQL);

        $this->mockHealthHttpClients(
            valhalla: new MockResponse('OK', ['http_code' => 200]),
            mercure: new MockResponse('', ['http_code' => 200]),
        );

        $response = $this->client->request('GET', '/api/health');

        $this->assertResponseStatusCodeSame(200);
        $reference = $response->toArray()['deps']['reference_data'];
        $this->assertSame('ok', $reference['status']);
        $this->assertSame(12, $reference['osm']['feature_counts']['pois']);
        $this->assertSame([], $reference['osm']['completeness']);
    }

    #[Test]
    public function readinessReportsTheOpenZonesAndTheRoutingContainmentInvariant(): void
    {
        // ADR-049 §6: nothing derives the routing slugs from the registry, so the
        // invariant "the routing perimeter encompasses the reference perimeter" is
        // checked rather than maintained — and this is where the check stays visible
        // after the provisioner refused (or did not get the chance to refuse).
        $this->seedZones(
            zones: [['bretagne', 'Bretagne', 'france'], ['belgique', 'Belgique', 'belgium']],
            perimeter: ['france'],
        );

        $this->mockHealthHttpClients(
            valhalla: new MockResponse('OK', ['http_code' => 200]),
            mercure: new MockResponse('', ['http_code' => 200]),
        );

        $response = $this->client->request('GET', '/api/health');

        $this->assertResponseStatusCodeSame(200);
        $data = $response->toArray();
        $zones = $data['deps']['reference_data']['zones'];

        // Ordered by slug, so Belgium comes first.
        $this->assertSame(['belgique', 'bretagne'], array_column($zones['open'], 'slug'));
        $this->assertFalse($zones['open'][0]['routable'], 'belgium is not in the graph');
        $this->assertTrue($zones['open'][1]['routable'], 'france is');
        $this->assertSame(['france'], $zones['routing_perimeter']);
        $this->assertSame('violated', $zones['routing_containment']['status']);
        $this->assertSame(['belgique'], $zones['routing_containment']['uncovered']);
        // An unrouteable zone degrades that zone's trips; it never takes readiness down.
        $this->assertSame('ok', $data['status']);
    }

    #[Test]
    public function readinessReportsSatisfiedContainmentWhenEveryOpenZoneIsRoutable(): void
    {
        $this->seedZones(zones: [['bretagne', 'Bretagne', 'france']], perimeter: ['france', 'belgium']);

        $this->mockHealthHttpClients(
            valhalla: new MockResponse('OK', ['http_code' => 200]),
            mercure: new MockResponse('', ['http_code' => 200]),
        );

        $zones = $this->client->request('GET', '/api/health')->toArray()['deps']['reference_data']['zones'];

        $this->assertSame('ok', $zones['routing_containment']['status']);
        $this->assertSame([], $zones['routing_containment']['uncovered']);
        // The inverse asymmetry is healthy: routing may cover a country with no
        // reference data at all (ADR-049 §6).
        $this->assertSame(['belgium', 'france'], $zones['routing_perimeter']);
    }

    #[Test]
    public function readinessReportsNoOpenZoneBeforeAnythingIsProvisioned(): void
    {
        $this->seedZones(zones: [], perimeter: []);

        $this->mockHealthHttpClients(
            valhalla: new MockResponse('OK', ['http_code' => 200]),
            mercure: new MockResponse('', ['http_code' => 200]),
        );

        $zones = $this->client->request('GET', '/api/health')->toArray()['deps']['reference_data']['zones'];

        $this->assertSame([], $zones['open']);
        $this->assertSame('ok', $zones['routing_containment']['status'], 'nothing open is nothing to cover');
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $zones     slug, name, country
     * @param list<string>                                 $perimeter country slugs the routing graph was built from
     */
    private function seedZones(array $zones, array $perimeter): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.reference_connection');
        \assert($connection instanceof Connection);
        $connection->executeStatement('TRUNCATE osm.zones, osm.routing_perimeter');

        foreach ($zones as $zone) {
            $connection->executeStatement(
                'INSERT INTO osm.zones (slug, name, country, opened_at, refreshed_at, pipeline_version) VALUES (?, ?, ?, now(), now(), 1)',
                $zone,
            );
        }

        foreach ($perimeter as $slug) {
            $connection->executeStatement('INSERT INTO osm.routing_perimeter (slug, observed_at) VALUES (?, now())', [$slug]);
        }
    }

    private function truncateProvisioningMetadata(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.reference_connection');
        \assert($connection instanceof Connection);
        $connection->executeStatement('TRUNCATE osm.metadata, tourism.metadata');
    }

    private function mockHealthHttpClients(
        MockResponse $valhalla,
        MockResponse $mercure,
    ): void {
        $container = self::getContainer();
        $container->set('routing.client', new MockHttpClient($valhalla));
        $container->set('mercure.health.client', new MockHttpClient($mercure));
    }
}
