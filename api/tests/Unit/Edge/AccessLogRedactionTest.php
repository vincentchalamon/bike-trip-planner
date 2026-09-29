<?php

declare(strict_types=1);

namespace App\Tests\Unit\Edge;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The edge access logs keep the credentials a URL may still carry out. Caddy's
 * patterns are read from the Caddyfile itself and applied the way Caddy applies
 * them (in order, RE2 `${1}` being PCRE `$1`), so an edit that breaks one fails here
 * rather than in a log store; Traefik, which cannot redact, logs no URL. Needs the
 * repository root (CI checks it out; a local container must mount `.docker` and
 * `ansible`).
 */
final class AccessLogRedactionTest extends TestCase
{
    private function caddyfile(): string
    {
        $path = \dirname(__DIR__, 4).'/.docker/php/Caddyfile';
        self::assertFileExists($path, 'mount the repository .docker directory next to api/');

        return (string) file_get_contents($path);
    }

    /**
     * @return list<array{string, string}> pattern, replacement
     */
    private function operations(string $field): array
    {
        $block = preg_quote($field, '/');
        self::assertSame(1, preg_match('/'.$block.' multi_regexp \{(.*?)\n\s*\}/s', $this->caddyfile(), $match), 'no multi_regexp filter on '.$field);
        preg_match_all('/regexp "((?:[^"\\\\]|\\\\.)*)" "([^"]*)"/', $match[1], $rules, \PREG_SET_ORDER);

        return array_map(static fn (array $rule): array => [$rule[1], str_replace('${1}', '$1', $rule[2])], $rules);
    }

    private function filter(string $field, string $value): string
    {
        foreach ($this->operations($field) as [$pattern, $replacement]) {
            $value = (string) preg_replace('#'.str_replace('#', '\#', $pattern).'#', $replacement, $value);
        }

        return $value;
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function uris(): iterable
    {
        yield 'share code' => ['/s/Ab3-_x9Z', '/s/[redacted]'];
        yield 'share download' => ['/s/Ab3-_x9Z.gpx', '/s/[redacted].gpx'];
        yield 'old magic link' => ['/auth/verify/0123abcd', '/auth/verify/[redacted]'];
        yield 'old email-change link' => ['/account/email-change/verify/0123abcd', '/account/email-change/verify/[redacted]'];
        yield 'old access-request link' => ['/access-requests/verify?email=a%40b.co&expires=1&signature=dead', '/access-requests/verify?email=[redacted]&expires=1&signature=[redacted]'];
        yield 'old fcm delete' => ['/users/me/device-tokens/fcm:APA91b-x', '/users/me/device-tokens/[redacted]'];
        yield 'unregister stays readable' => ['/users/me/device-tokens/unregister', '/users/me/device-tokens/unregister'];
        yield 'trip id stays readable' => ['/trips/0199a1b2-0000-7000-8000-000000000001', '/trips/0199a1b2-0000-7000-8000-000000000001'];
    }

    #[Test]
    #[DataProvider('uris')]
    public function theAccessLogRedactsTheUri(string $uri, string $logged): void
    {
        self::assertSame($logged, $this->filter('request>uri', $uri));
    }

    #[Test]
    public function theAccessLogRedactsTheReferer(): void
    {
        self::assertSame('https://h/s/[redacted]?token=[redacted]', $this->filter('request>headers>Referer', 'https://h/s/Ab3-_x9Z?token=t'));
    }

    #[Test]
    public function noFullUrlLeavesAsReferer(): void
    {
        self::assertStringContainsString('header ?Referrer-Policy "strict-origin"', $this->caddyfile());
    }

    /** Traefik cannot redact a path segment, so it logs neither the path nor the query. */
    #[Test]
    public function traefikLogsNoUrl(): void
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 4).'/ansible/roles/traefik/templates/traefik.yml.j2');
        \assert(\is_array($config) && \is_array($config['accessLog'] ?? null));

        self::assertSame(['names' => ['RequestPath' => 'drop'], 'queryParameters' => ['defaultMode' => 'drop']], $config['accessLog']['fields'] ?? null);
    }
}
