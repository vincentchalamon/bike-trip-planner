<?php

declare(strict_types=1);

namespace App\Tests\Unit\Edge;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Least privilege of the iso-prod stack (SEC-19) and the Redis / Mercure exposure (SEC-21).
 */
final class ContainerHardeningTest extends TestCase
{
    private const array CAPABILITIES = [
        'php' => ['DAC_OVERRIDE', 'NET_BIND_SERVICE'],
        'worker' => ['DAC_OVERRIDE'],
        'pwa' => [],
        'database' => ['CHOWN', 'DAC_OVERRIDE', 'FOWNER', 'SETGID', 'SETUID'],
        'redis' => ['CHOWN', 'SETGID', 'SETUID'],
    ];

    #[Test]
    #[DataProvider('services')]
    public function aServiceKeepsOnlyTheCapabilitiesItUses(string $name): void
    {
        $service = $this->service($name);

        self::assertSame(['no-new-privileges:true'], $service['security_opt'] ?? null);
        self::assertSame(['ALL'], $service['cap_drop'] ?? null);
        self::assertSame(self::CAPABILITIES[$name], $service['cap_add'] ?? []);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function services(): iterable
    {
        foreach (array_keys(self::CAPABILITIES) as $name) {
            yield $name => [$name];
        }
    }

    #[Test]
    public function onlyTheirClientsReachTheDataStores(): void
    {
        self::assertSame(['backend'], $this->service('database')['networks'] ?? null);
        self::assertSame(['backend'], $this->service('redis')['networks'] ?? null);
        self::assertNotContains('backend', $this->service('pwa')['networks'] ?? ['default']);
        self::assertContains('backend', $this->service('php')['networks'] ?? []);
        self::assertContains('backend', $this->service('worker')['networks'] ?? []);
    }

    #[Test]
    public function redisRequiresItsPasswordEverywhere(): void
    {
        $password = '${REDIS_PASSWORD:-ChangeMeRedis}';
        $command = $this->service('redis')['command'] ?? null;
        self::assertIsArray($command);
        self::assertSame($password, $command[array_search('--requirepass', $command, true) + 1] ?? null);

        foreach (['php', 'worker'] as $name) {
            $environment = $this->service($name)['environment'] ?? null;
            self::assertIsArray($environment);
            foreach (['MESSENGER_TRANSPORT_DSN', 'MESSENGER_FAILED_DSN', 'REDIS_URL', 'LOCK_DSN'] as $dsn) {
                self::assertStringStartsWith('redis://:'.$password.'@redis:6379', (string) ($environment[$dsn] ?? ''), $name.' '.$dsn);
            }
        }

        // The preview overlay replaces the whole command: it must keep the password.
        $preview = Yaml::parseFile(self::root().'/deploy/preview/compose.yaml', Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($preview);
        self::assertContains('--requirepass', $preview['services']['redis']['command'] ?? []);
    }

    #[Test]
    public function theMercureHubAndCaddyAdminTakeNoAnonymousRequest(): void
    {
        $lines = array_map(trim(...), file(self::root().'/.docker/php/Caddyfile', \FILE_IGNORE_NEW_LINES) ?: []);

        self::assertContains('admin off', $lines);
        self::assertNotContains('anonymous', $lines);
    }

    #[Test]
    public function traefikReadsTheDockerApiThroughTheProxyOnly(): void
    {
        $compose = Yaml::parse((string) preg_replace('/\{\{[^}]*\}\}/', 'x', (string) file_get_contents(self::root().'/ansible/roles/traefik/templates/compose.yaml.j2')));
        self::assertIsArray($compose);

        foreach ($compose['services']['traefik']['volumes'] ?? [] as $volume) {
            self::assertStringNotContainsString('docker.sock', (string) $volume);
        }
        self::assertSame('0', $compose['services']['socket-proxy']['environment']['POST'] ?? null);
        self::assertTrue($compose['networks']['docker-api']['internal'] ?? false);
    }

    /**
     * @return array<mixed>
     */
    private function service(string $name): array
    {
        $compose = Yaml::parseFile(self::root().'/compose.yaml');
        self::assertIsArray($compose);
        $service = $compose['services'][$name] ?? null;
        self::assertIsArray($service);

        return $service;
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 4);
    }
}
