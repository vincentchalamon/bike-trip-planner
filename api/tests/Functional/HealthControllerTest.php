<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
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
        // Reset overridden services so that the broken-Postgres mock and the
        // unreachable REDIS_URL injected by a given test do not leak into
        // subsequent ones — the kernel is reused ($alwaysBootKernel = false).
        $container = self::getContainer();
        $container->reset();
        putenv('REDIS_URL');
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
        foreach (['postgres', 'redis', 'mercure', 'valhalla', 'ollama'] as $dep) {
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

        $brokenConnection = $this->createMock(Connection::class);
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
    public function readinessReturns503WhenRedisIsDown(): void
    {
        // Bind an unreachable Redis URL before the kernel is booted so the
        // HealthController is built with it as its `$redisUrl` arg.
        $_ENV['REDIS_URL'] = 'redis://127.0.0.1:19999';
        $_SERVER['REDIS_URL'] = 'redis://127.0.0.1:19999';
        self::ensureKernelShutdown();
        $client = self::createClient();

        // Now register the mocked HTTP clients on the freshly booted container.
        $container = self::getContainer();
        $container->set('routing.client', new MockHttpClient(new MockResponse('OK', ['http_code' => 200])));
        $container->set('ollama.client', new MockHttpClient(new MockResponse('{"models":[]}', ['http_code' => 200])));
        $container->set('mercure.health.client', new MockHttpClient(new MockResponse('', ['http_code' => 200])));

        $response = $client->request('GET', '/api/health');

        unset($_ENV['REDIS_URL'], $_SERVER['REDIS_URL']);

        $this->assertResponseStatusCodeSame(503);
        $data = $response->toArray(false);
        $this->assertSame('down', $data['deps']['redis']['status']);
    }

    private function mockHealthHttpClients(
        MockResponse $valhalla,
        MockResponse $ollama,
        MockResponse $mercure,
    ): void {
        $container = self::getContainer();
        $container->set('routing.client', new MockHttpClient($valhalla));
        $container->set('ollama.client', new MockHttpClient($ollama));
        $container->set('mercure.health.client', new MockHttpClient($mercure));
    }
}
