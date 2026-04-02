<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\UrlSafetyValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UrlSafetyValidatorTest extends TestCase
{
    private UrlSafetyValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new UrlSafetyValidator();
    }

    #[Test]
    public function itRejectsEmptyHost(): void
    {
        self::assertFalse($this->validator->isPublicUrl('https://'));
    }

    #[Test]
    public function itRejectsInvalidUrl(): void
    {
        self::assertFalse($this->validator->isPublicUrl('not-a-url'));
    }

    #[Test]
    #[DataProvider('internalUrlProvider')]
    public function itRejectsInternalUrls(string $url): void
    {
        self::assertFalse($this->validator->isPublicUrl($url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function internalUrlProvider(): iterable
    {
        yield 'loopback' => ['https://127.0.0.1/page'];
        yield 'localhost' => ['https://localhost/page'];
        yield 'private 10.x' => ['https://10.0.0.1/page'];
        yield 'private 172.16.x' => ['https://172.16.0.1/page'];
        yield 'private 192.168.x' => ['https://192.168.1.1/page'];
        yield 'link-local' => ['https://169.254.169.254/latest/meta-data/'];
    }

    #[Test]
    public function itAcceptsPublicIpUrl(): void
    {
        // 93.184.215.14 is IANA's example.com — a known public IP, no DNS required
        self::assertTrue($this->validator->isPublicUrl('https://93.184.215.14/page'));
    }
}
