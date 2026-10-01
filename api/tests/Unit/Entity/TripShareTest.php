<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\TripShare;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TripShare::class)]
final class TripShareTest extends TestCase
{
    #[Test]
    public function isActiveReturnsTrueByDefault(): void
    {
        $share = new TripShare();

        $this->assertTrue($share->isActive());
        $this->assertNull($share->getDeletedAt());
    }

    #[Test]
    public function softDeleteSetsDeletedAt(): void
    {
        $share = new TripShare();
        $share->softDelete();

        $this->assertFalse($share->isActive());
        $this->assertNotNull($share->getDeletedAt());
    }

    #[Test]
    public function generateTokenProduces64HexCharacters(): void
    {
        $share = new TripShare();
        $share->generateToken();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $share->getToken());
    }
}
