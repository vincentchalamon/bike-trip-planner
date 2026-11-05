<?php

declare(strict_types=1);

namespace App\Tests\Unit\Edge;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The prod entrypoint refuses an empty JWT passphrase and the public compose
 * defaults of the PostgreSQL and Redis passwords (SEC-20, SEC-21), before it
 * hands over to the server.
 */
final class EntrypointCredentialGuardTest extends TestCase
{
    #[Test]
    public function theGuardsRunBeforeTheServerStarts(): void
    {
        $script = $this->script();
        $exec = strpos($script, 'exec docker-php-entrypoint "$@"');
        self::assertIsInt($exec);

        foreach ($this->guards() as $guard) {
            $at = strpos($script, $guard);
            self::assertIsInt($at, 'missing guard: '.$guard);
            self::assertLessThan($exec, $at, 'guard after the exec: '.$guard);
        }
    }

    #[Test]
    public function theOptOutCoversThePasswordDefaultsOnly(): void
    {
        $script = $this->script();
        $optOut = strpos($script, 'if [ "${ALLOW_LOCAL_DEFAULT_CREDENTIALS:-false}" != \'true\' ]; then');
        self::assertIsInt($optOut);
        self::assertSame(1, preg_match('/^fi$/m', $script, $end, \PREG_OFFSET_CAPTURE, $optOut));
        $close = $end[0][1];

        [$passphrase, $database, $redis] = $this->guards();
        self::assertLessThan($optOut, strpos($script, $passphrase), 'the opt-out must not cover an empty passphrase');
        foreach ([$database, $redis] as $guard) {
            $at = strpos($script, $guard);
            self::assertIsInt($at);
            self::assertGreaterThan($optOut, $at);
            self::assertLessThan($close, $at);
        }
    }

    /**
     * The compose defaults the guards match must be the ones compose.yaml ships.
     */
    #[Test]
    public function theGuardsMatchTheComposeDefaults(): void
    {
        $compose = (string) file_get_contents(\dirname(__DIR__, 4).'/compose.yaml');

        self::assertStringContainsString('${DATABASE_PASSWORD:-!ChangeMe!}@database', $compose);
        self::assertStringContainsString('redis://:${REDIS_PASSWORD:-ChangeMeRedis}@redis', $compose);
    }

    /**
     * @return array{string, string, string}
     */
    private function guards(): array
    {
        return [
            'if [ -z "${JWT_PASSPHRASE:-}" ]; then',
            "*':!ChangeMe!@'*)",
            "*':ChangeMeRedis@'*)",
        ];
    }

    private function script(): string
    {
        $script = file_get_contents(\dirname(__DIR__, 4).'/.docker/php/entrypoint.sh');
        self::assertIsString($script);

        return $script;
    }
}
