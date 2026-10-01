<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\FrontendUrl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FrontendUrl::class)]
final class FrontendUrlTest extends TestCase
{
    #[Test]
    public function aTrailingSlashOnTheOriginDoesNotDoubleUp(): void
    {
        self::assertSame('https://app.example/login', new FrontendUrl('https://app.example/')->to('/login'));
        self::assertSame('https://app.example/s/abc', new FrontendUrl('https://app.example')->to('/s/abc'));
    }
}
