<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Delete;
use App\Entity\TripShare;
use App\State\TripShareDeleteProcessor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TripShareDeleteProcessorTest extends TestCase
{
    #[Test]
    public function itSoftDeletesTheShare(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $share = new TripShare();
        self::assertTrue($share->isActive());

        $processor = new TripShareDeleteProcessor($entityManager);
        $processor->process($share, new Delete());

        self::assertFalse($share->isActive());
        self::assertNotNull($share->getDeletedAt());
    }
}
