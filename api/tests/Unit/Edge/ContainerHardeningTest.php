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

    private const string REDIS_PASSWORD = '${REDIS_PASSWORD:-ChangeMeRedis}';

    #[Test]
    #[DataProvider('services')]
    public function aServiceKeepsOnlyTheCapabilitiesItUses(string $name): void
    {
        $compose = $this->compose();

        self::assertSame(['no-new-privileges:true'], $this->at($compose, 'services', $name, 'security_opt'));
        self::assertSame(['ALL'], $this->at($compose, 'services', $name, 'cap_drop'));
        self::assertSame(self::CAPABILITIES[$name], $this->at($compose, 'services', $name, 'cap_add') ?? []);
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
        $compose = $this->compose();

        self::assertSame(['backend'], $this->at($compose, 'services', 'database', 'networks'));
        self::assertSame(['backend'], $this->at($compose, 'services', 'redis', 'networks'));
        self::assertNotContains('backend', $this->listAt($compose, 'services', 'pwa', 'networks'));
        self::assertContains('backend', $this->listAt($compose, 'services', 'php', 'networks'));
        self::assertContains('backend', $this->listAt($compose, 'services', 'worker', 'networks'));
    }

    #[Test]
    public function redisRequiresItsPasswordEverywhere(): void
    {
        $compose = $this->compose();
        $command = $this->listAt($compose, 'services', 'redis', 'command');
        $flag = array_search('--requirepass', $command, true);
        self::assertIsInt($flag);
        self::assertSame(self::REDIS_PASSWORD, $command[$flag + 1] ?? null);

        foreach (['php', 'worker'] as $name) {
            foreach (['MESSENGER_TRANSPORT_DSN', 'MESSENGER_FAILED_DSN', 'REDIS_URL', 'LOCK_DSN'] as $dsn) {
                $value = $this->at($compose, 'services', $name, 'environment', $dsn);
                self::assertIsString($value, $name.' '.$dsn);
                self::assertStringStartsWith('redis://:'.self::REDIS_PASSWORD.'@redis:6379', $value, $name.' '.$dsn);
            }
        }

        // The preview overlay replaces the whole command: it must keep the password.
        $preview = Yaml::parseFile($this->root().'/deploy/preview/compose.yaml', Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($preview);
        self::assertContains('--requirepass', $this->listAt($preview, 'services', 'redis', 'command'));
    }

    #[Test]
    public function theMercureHubAndCaddyAdminTakeNoAnonymousRequest(): void
    {
        $lines = array_map(trim(...), file($this->root().'/.docker/php/Caddyfile', \FILE_IGNORE_NEW_LINES) ?: []);

        self::assertContains('admin off', $lines);
        self::assertNotContains('anonymous', $lines);
    }

    #[Test]
    public function traefikReadsTheDockerApiThroughTheProxyOnly(): void
    {
        $template = file_get_contents($this->root().'/ansible/roles/traefik/templates/compose.yaml.j2');
        self::assertIsString($template);
        $compose = Yaml::parse((string) preg_replace('/\{\{[^}]*\}\}/', 'x', $template));
        self::assertIsArray($compose);

        foreach ($this->listAt($compose, 'services', 'traefik', 'volumes') as $volume) {
            self::assertIsString($volume);
            self::assertStringNotContainsString('docker.sock', $volume);
        }

        self::assertSame('0', $this->at($compose, 'services', 'socket-proxy', 'environment', 'POST'));
        self::assertTrue($this->at($compose, 'networks', 'docker-api', 'internal'));
    }

    /**
     * @return array<mixed>
     */
    private function compose(): array
    {
        $compose = Yaml::parseFile($this->root().'/compose.yaml');
        self::assertIsArray($compose);

        return $compose;
    }

    /**
     * @param array<mixed> $tree
     */
    private function at(array $tree, string ...$path): mixed
    {
        $node = $tree;
        foreach ($path as $key) {
            if (!\is_array($node) || !\array_key_exists($key, $node)) {
                return null;
            }

            $node = $node[$key];
        }

        return $node;
    }

    /**
     * @param array<mixed> $tree
     *
     * @return array<mixed>
     */
    private function listAt(array $tree, string ...$path): array
    {
        $node = $this->at($tree, ...$path) ?? [];
        self::assertIsArray($node, implode('.', $path));

        return $node;
    }

    private function root(): string
    {
        return \dirname(__DIR__, 4);
    }
}
