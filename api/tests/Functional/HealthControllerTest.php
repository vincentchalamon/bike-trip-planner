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
        // A test may flip OLLAMA_ENABLED and reboot the kernel (see
        // bootWithOllamaEnabled): restore the default (0) and drop the kernel so the
        // next test boots fresh with a clean env and no leaked service overrides
        // (broken Postgres/Redis, mocked HTTP clients).
        $_SERVER['OLLAMA_ENABLED'] = $_ENV['OLLAMA_ENABLED'] = '0';
        self::ensureKernelShutdown();
        parent::tearDown();
    }

    #[Test]
    public function livenessReturns200WithCommitSha(): void
    {
        $response = $this->client->request('GET', '/api/healthz');

        $this->assertResponseStatusCodeSame(200);

        $data = $response->toArray();
        $this->assertSame('ok', $data['status']);
        $this->assertArrayHasKey('commit', $data);
        $this->assertIsString($data['commit']);
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
        $this->bootWithOllamaEnabled();
        $this->mockHealthHttpClients(
            valhalla: new MockResponse('OK', ['http_code' => 200]),
            ollama: new MockResponse('{"models":[]}', ['http_code' => 200]),
            mercure: new MockResponse('', ['http_code' => 200]),
        );

        $response = $this->client->request('GET', '/api/health');

        $this->assertResponseStatusCodeSame(200);

        $data = $response->toArray();
        $this->assertSame('ok', $data['status']);
        $this->assertArrayHasKey('deps', $data);
        foreach (['postgres', 'redis', 'mercure', 'valhalla', 'ollama_chat', 'ollama_analysis', 'reference_data'] as $dep) {
            $this->assertArrayHasKey($dep, $data['deps'], \sprintf('Missing dep %s', $dep));
            $this->assertArrayHasKey('status', $data['deps'][$dep]);
            $this->assertArrayHasKey('latency_ms', $data['deps'][$dep]);
        }
    }

    #[Test]
    public function readinessReturns503WhenValhallaIsDown(): void
    {
        $this->mockHealthHttpClients(
            valhalla: new MockResponse('boom', ['http_code' => 500]),
            ollama: new MockResponse('{"models":[]}', ['http_code' => 200]),
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
            ollama: new MockResponse('{"models":[]}', ['http_code' => 200]),
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
            ollama: new MockResponse('{"models":[]}', ['http_code' => 200]),
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
    public function readinessReturns503WhenRedisIsDown(): void
    {
        $this->mockHealthHttpClients(
            valhalla: new MockResponse('OK', ['http_code' => 200]),
            ollama: new MockResponse('{"models":[]}', ['http_code' => 200]),
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
    public function readinessReturns200WhenOllamaIsDown(): void
    {
        // Ollama is intentionally excluded from $required, so its outage must
        // not flip the aggregate status — pin that contract here.
        $this->bootWithOllamaEnabled();
        $this->mockHealthHttpClients(
            valhalla: new MockResponse('OK', ['http_code' => 200]),
            ollama: new MockResponse('error', ['http_code' => 500]),
            mercure: new MockResponse('', ['http_code' => 200]),
        );

        $response = $this->client->request('GET', '/api/health');

        $this->assertResponseStatusCodeSame(200);
        $data = $response->toArray();
        $this->assertSame('ok', $data['status']);
        $this->assertSame('down', $data['deps']['ollama_chat']['status']);
    }

    #[Test]
    public function readinessReturns200WhenOllamaAnalysisIsDown(): void
    {
        // The analysis client is also excluded from $required; an analysis-only
        // outage surfaces in deps.ollama_analysis but must not flip the aggregate.
        $this->bootWithOllamaEnabled();
        $this->mockHealthHttpClients(
            valhalla: new MockResponse('OK', ['http_code' => 200]),
            ollama: new MockResponse('{"models":[]}', ['http_code' => 200]),
            mercure: new MockResponse('', ['http_code' => 200]),
            ollamaAnalysis: new MockResponse('error', ['http_code' => 503]),
        );

        $response = $this->client->request('GET', '/api/health');

        $this->assertResponseStatusCodeSame(200);
        $data = $response->toArray();
        $this->assertSame('ok', $data['status']);
        $this->assertSame('down', $data['deps']['ollama_analysis']['status']);
    }

    #[Test]
    public function readinessOmitsOllamaWhenAiDisabled(): void
    {
        // OLLAMA_ENABLED=0 (the test-suite default): the LLM tier is neither probed
        // nor surfaced — its very absence from `deps` is the "AI disabled" signal.
        $this->mockHealthHttpClients(
            valhalla: new MockResponse('OK', ['http_code' => 200]),
            ollama: new MockResponse('{"models":[]}', ['http_code' => 200]),
            mercure: new MockResponse('', ['http_code' => 200]),
        );

        $response = $this->client->request('GET', '/api/health');

        $this->assertResponseStatusCodeSame(200);
        $data = $response->toArray();
        $this->assertSame('ok', $data['status']);
        $this->assertArrayNotHasKey('ollama_chat', $data['deps']);
        $this->assertArrayNotHasKey('ollama_analysis', $data['deps']);
    }

    #[Test]
    public function readinessReportsUnprovisionedReferenceDataWithoutFlippingStatus(): void
    {
        // An empty PostGIS index (no provisioning run yet) surfaces as down in
        // reference_data but is non-required, so the aggregate stays ok (ADR-040).
        $this->truncateProvisioningMetadata();
        $this->mockHealthHttpClients(
            valhalla: new MockResponse('OK', ['http_code' => 200]),
            ollama: new MockResponse('{"models":[]}', ['http_code' => 200]),
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
    public function readinessReportsReferenceDataFreshnessAndCounts(): void
    {
        $this->truncateProvisioningMetadata();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);
        $connection->executeStatement(<<<'SQL'
            INSERT INTO osm.metadata (refreshed_at, feature_counts)
            VALUES (now(), '{"pois": 12, "admin_boundaries": 4}'::jsonb)
            SQL);

        $this->mockHealthHttpClients(
            valhalla: new MockResponse('OK', ['http_code' => 200]),
            ollama: new MockResponse('{"models":[]}', ['http_code' => 200]),
            mercure: new MockResponse('', ['http_code' => 200]),
        );

        $response = $this->client->request('GET', '/api/health');

        $this->assertResponseStatusCodeSame(200);
        $data = $response->toArray();
        $reference = $data['deps']['reference_data'];
        $this->assertSame('ok', $reference['status']);
        $this->assertNotNull($reference['osm']);
        $this->assertIsString($reference['osm']['refreshed_at']);
        $this->assertFalse($reference['osm']['stale'], 'a just-refreshed index is fresh');
        $this->assertLessThan(60, $reference['osm']['age_seconds']);
        $this->assertSame(12, $reference['osm']['feature_counts']['pois']);
        $this->assertSame(4, $reference['osm']['feature_counts']['admin_boundaries']);
        $this->assertNull($reference['tourism'], 'tourism index still unprovisioned');
    }

    #[Test]
    public function readinessFlagsAStaleReferenceIndexWithoutFailingReadiness(): void
    {
        $this->truncateProvisioningMetadata();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);
        // OSM refreshed 10 days ago — past the 8-day weekly-cadence threshold.
        $connection->executeStatement(<<<'SQL'
            INSERT INTO osm.metadata (refreshed_at, feature_counts)
            VALUES (now() - interval '10 days', '{"pois": 12}'::jsonb)
            SQL);

        $this->mockHealthHttpClients(
            valhalla: new MockResponse('OK', ['http_code' => 200]),
            ollama: new MockResponse('{"models":[]}', ['http_code' => 200]),
            mercure: new MockResponse('', ['http_code' => 200]),
        );

        $response = $this->client->request('GET', '/api/health');

        // Stale data degrades features only — readiness stays 200 (ADR-040/041).
        $this->assertResponseStatusCodeSame(200);
        $data = $response->toArray();
        $this->assertSame('ok', $data['status'], 'reference_data is non-required');
        $reference = $data['deps']['reference_data'];
        $this->assertSame('stale', $reference['status']);
        $this->assertTrue($reference['osm']['stale']);
        $this->assertGreaterThan(8 * 86400, $reference['osm']['age_seconds']);
    }

    private function truncateProvisioningMetadata(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);
        $connection->executeStatement('TRUNCATE osm.metadata, tourism.metadata');
    }

    /**
     * Reboot the kernel with OLLAMA_ENABLED=1 so the readiness probe surfaces the LLM
     * tier. Must be called before mocking the HTTP clients (the reboot rebuilds the
     * container). tearDown restores the default (0) and drops the kernel.
     */
    private function bootWithOllamaEnabled(): void
    {
        $_SERVER['OLLAMA_ENABLED'] = $_ENV['OLLAMA_ENABLED'] = '1';
        self::ensureKernelShutdown();
        $this->client = self::createClient();
    }

    private function mockHealthHttpClients(
        MockResponse $valhalla,
        MockResponse $ollama,
        MockResponse $mercure,
        ?MockResponse $ollamaAnalysis = null,
    ): void {
        $container = self::getContainer();
        $container->set('routing.client', new MockHttpClient($valhalla));
        $container->set('ollama_chat.client', new MockHttpClient($ollama));
        $container->set('ollama_analysis.client', new MockHttpClient($ollamaAnalysis ?? new MockResponse('{"models":[]}', ['http_code' => 200])));
        $container->set('mercure.health.client', new MockHttpClient($mercure));
    }
}
