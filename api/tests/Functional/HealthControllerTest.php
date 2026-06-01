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
        // Reset overridden services so that the broken-Postgres / broken-Redis
        // mock injected by a given test does not leak into subsequent ones —
        // the kernel is reused ($alwaysBootKernel = false).
        $container = self::getContainer();
        $container->reset();
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
        foreach (['postgres', 'redis', 'mercure', 'valhalla', 'ollama_chat', 'ollama_analysis'] as $dep) {
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
