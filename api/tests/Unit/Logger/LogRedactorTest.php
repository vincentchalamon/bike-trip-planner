<?php

declare(strict_types=1);

namespace App\Tests\Unit\Logger;

use App\Logger\LogRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LogRedactorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function leaks(): iterable
    {
        yield 'address' => ['user="rider@example.com"', 'user="[email]"'];
        yield 'url-encoded address' => ['?email=rider%40example.com', '?email=[redacted]'];
        yield 'address in a driver message' => ['Key (email)=(rider.name+tag@mail.example.co.uk) already exists.', 'Key (email)=([email]) already exists.'];
        yield 'magic-link path token' => ['https://h/auth/verify/0123abcd', 'https://h/auth/verify/[redacted]'];
        yield 'magic-link fragment token' => ['https://h/auth/verify#0123abcd', 'https://h/auth/verify#[redacted]'];
        yield 'email-change token' => ['/account/email-change/verify/0123abcd', '/account/email-change/verify/[redacted]'];
        yield 'access-request fragment' => ['/access-requests/verify#id=0199&expires=1&signature=ab', '/access-requests/verify#[redacted]'];
        yield 'share code' => ['GET /s/Ab3-_x9Z', 'GET /s/[redacted]'];
        yield 'share code download' => ['/s/Ab3-_x9Z.gpx', '/s/[redacted].gpx'];
        yield 'share code sub-resource' => ['/s/Ab3-_x9Z/stages/2', '/s/[redacted]/stages/2'];
        yield 'fcm token in a path' => ['/users/me/device-tokens/fcm:APA91b-x_y', '/users/me/device-tokens/[redacted]'];
        yield 'signature query' => ['/verify?expires=1&signature=abcdef', '/verify?expires=1&signature=[redacted]'];
        yield 'api key query' => ['https://api.example/events?apikey=s3cr3t&size=20', 'https://api.example/events?apikey=[redacted]&size=20'];
        yield 'oauth code' => ['/callback?code=xyz&state=abc', '/callback?code=[redacted]&state=[redacted]'];
    }

    #[Test]
    #[DataProvider('leaks')]
    public function redactsWhatMustNotBeWrittenDown(string $text, string $expected): void
    {
        self::assertSame($expected, LogRedactor::text($text));
    }

    #[Test]
    public function leavesOrdinaryTextAlone(): void
    {
        $text = 'Matched route "_api_/s/{shortCode}{._format}_get" /trips/0199a1b2-0000-7000-8000-000000000001 /docs/ /users/me/device-tokens/unregister';

        self::assertSame($text, LogRedactor::text($text));
    }

    #[Test]
    public function aUrlLosesItsQueryAndFragment(): void
    {
        self::assertSame('https://h/geocode/reverse', LogRedactor::url('https://h/geocode/reverse?lat=45.1&lon=5.7'));
        self::assertSame('https://h/s/[redacted]', LogRedactor::url('https://h/s/Ab3-_x9Z#top'));
    }

    #[Test]
    public function redactsNestedArraysAndSecretKeys(): void
    {
        self::assertSame(
            ['route_parameters' => ['shortCode' => '[redacted]', '_route' => 'share'], 'request_uri' => 'https://h/s/[redacted]', 'n' => 3, 'email' => '[redacted]'],
            LogRedactor::array(['route_parameters' => ['shortCode' => 'Ab3-_x9Z', '_route' => 'share'], 'request_uri' => 'https://h/s/Ab3-_x9Z', 'n' => 3, 'email' => 'rider@example.com']),
        );
    }
}
