<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Post;
use App\ApiResource\Account\DeviceToken as DeviceTokenResource;
use App\Entity\User;
use App\Enum\DevicePlatform;
use App\Repository\DeviceTokenRepositoryInterface;
use App\State\Account\DeviceTokenRegisterProcessor;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

#[AllowMockObjectsWithoutExpectations]
final class DeviceTokenRegisterProcessorTest extends TestCase
{
    private MockObject&Security $security;

    private MockObject&EntityManagerInterface $entityManager;

    private MockObject&DeviceTokenRepositoryInterface $deviceTokenRepository;

    private DeviceTokenRegisterProcessor $processor;

    #[\Override]
    protected function setUp(): void
    {
        $this->security = $this->createMock(Security::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->deviceTokenRepository = $this->createMock(DeviceTokenRepositoryInterface::class);

        $this->processor = new DeviceTokenRegisterProcessor(
            $this->security,
            $this->entityManager,
            $this->deviceTokenRepository,
            $this->createStub(LoggerInterface::class),
        );
    }

    #[Test]
    public function itThrows409OnConcurrentInsertRaceCondition(): void
    {
        $this->security->method('getUser')->willReturn(new User('race@example.com'));

        // No token found: the processor takes the create path and persists a new row.
        $this->deviceTokenRepository->expects($this->once())->method('findOneByToken')->willReturn(null);
        $this->entityManager->expects($this->once())->method('persist');

        // A concurrent request inserted the same token first: flush hits the unique
        // constraint. The processor must translate it to a 409, not let a 500 leak.
        $uniqueException = $this->getMockBuilder(UniqueConstraintViolationException::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->entityManager->expects($this->once())->method('flush')->willThrowException($uniqueException);

        $this->expectException(ConflictHttpException::class);
        $this->processor->process(new DeviceTokenResource('fcm-race', DevicePlatform::ANDROID), new Post());
    }
}
