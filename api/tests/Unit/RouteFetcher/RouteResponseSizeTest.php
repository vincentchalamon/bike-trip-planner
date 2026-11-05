<?php

declare(strict_types=1);

namespace App\Tests\Unit\RouteFetcher;

use App\RouteFetcher\RouteResponseSize;
use App\RouteParser\RouteTooLargeException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouteResponseSizeTest extends TestCase
{
    #[Test]
    public function aResponseAtTheCeilingIsLetThrough(): void
    {
        $this->expectNotToPerformAssertions();

        (RouteResponseSize::guard())(RouteResponseSize::MAX_BYTES, RouteResponseSize::MAX_BYTES);
    }

    #[Test]
    public function aDeclaredLengthPastTheCeilingAbortsAtOnce(): void
    {
        $this->expectException(RouteTooLargeException::class);

        (RouteResponseSize::guard())(0, RouteResponseSize::MAX_BYTES + 1);
    }

    #[Test]
    public function anUndeclaredLengthIsBoundedOnTheStream(): void
    {
        // A chunked response declares no length (0): only the bytes received can tell.
        $this->expectException(RouteTooLargeException::class);

        (RouteResponseSize::guard())(RouteResponseSize::MAX_BYTES + 1, 0);
    }
}
