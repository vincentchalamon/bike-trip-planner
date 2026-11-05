<?php

declare(strict_types=1);

namespace App\Tests\Unit\Edge;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The prod entrypoint refuses an empty JWT passphrase and the public compose
 * defaults of the PostgreSQL and Redis passwords (SEC-20, SEC-21).
 */
final class EntrypointCredentialGuardTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/entrypoint-guard-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/bin', 0o700, true);
        foreach (['jwt', 'oauth'] as $pair) {
            file_put_contents($this->dir.'/'.$pair.'-private.pem', $pair.' private');
            file_put_contents($this->dir.'/'.$pair.'-public.pem', $pair.' public');
        }

        // Stands in for the upstream entrypoint the script execs at the end.
        file_put_contents($this->dir.'/bin/docker-php-entrypoint', "#!/bin/sh\necho booted\n");
        chmod($this->dir.'/bin/docker-php-entrypoint', 0o700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/{,bin/}*', \GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->dir.'/bin');
        rmdir($this->dir);
    }

    #[Test]
    public function realCredentialsBoot(): void
    {
        [$code, $output] = $this->boot([]);

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('booted', $output);
    }

    /**
     * @param array<string, string> $env
     */
    #[Test]
    #[DataProvider('refused')]
    public function aPublicDefaultIsRefused(array $env, string $message): void
    {
        [$code, $output] = $this->boot($env);

        self::assertSame(1, $code, $output);
        self::assertStringContainsString($message, $output);
        self::assertStringNotContainsString('booted', $output);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function refused(): iterable
    {
        yield 'empty JWT passphrase' => [['JWT_PASSPHRASE' => ''], 'JWT_PASSPHRASE is empty'];
        yield 'default database password' => [
            ['DATABASE_URL' => 'postgresql://app:!ChangeMe!@database:5432/app?serverVersion=18'],
            'DATABASE_PASSWORD is unset or still the compose default',
        ];
        yield 'default Redis password' => [
            ['REDIS_URL' => 'redis://:ChangeMeRedis@redis:6379'],
            'REDIS_PASSWORD is unset or still the compose default',
        ];
    }

    #[Test]
    public function theLocalIsoProdStacksMayKeepTheDefaults(): void
    {
        [$code, $output] = $this->boot([
            'ALLOW_LOCAL_DEFAULT_CREDENTIALS' => 'true',
            'DATABASE_URL' => 'postgresql://app:!ChangeMe!@database:5432/app?serverVersion=18',
            'REDIS_URL' => 'redis://:ChangeMeRedis@redis:6379',
        ]);

        self::assertSame(0, $code, $output);
    }

    #[Test]
    public function theOptOutDoesNotCoverAnEmptyPassphrase(): void
    {
        [$code] = $this->boot(['ALLOW_LOCAL_DEFAULT_CREDENTIALS' => 'true', 'JWT_PASSPHRASE' => '']);

        self::assertSame(1, $code);
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array{int, string}
     */
    private function boot(array $overrides): array
    {
        $env = [
            'PATH' => $this->dir.'/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'MERCURE_JWT_SECRET' => str_repeat('m', 32),
            'REFRESH_TOKEN_ENC_KEY' => 'test-refresh-key',
            'JWT_SECRET_KEY' => $this->dir.'/jwt-private.pem',
            'JWT_PUBLIC_KEY' => $this->dir.'/jwt-public.pem',
            'OAUTH_PRIVATE_KEY' => $this->dir.'/oauth-private.pem',
            'OAUTH_PUBLIC_KEY' => $this->dir.'/oauth-public.pem',
            'OAUTH_ENCRYPTION_KEY' => 'test-oauth-key',
            'APP_SECRET' => 'test-app-secret',
            'JWT_PASSPHRASE' => 'test-passphrase',
            'DATABASE_URL' => 'postgresql://app:s3cret@database:5432/app?serverVersion=18',
            'REDIS_URL' => 'redis://:0123456789abcdef@redis:6379',
            'MIGRATIONS_ON_BOOT' => 'false',
            ...$overrides,
        ];

        $process = proc_open(
            ['sh', \dirname(__DIR__, 4).'/.docker/php/entrypoint.sh'],
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $this->dir,
            $env,
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }
}
