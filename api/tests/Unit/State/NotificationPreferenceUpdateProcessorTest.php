<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Put;
use App\ApiResource\Account\NotificationPreference as NotificationPreferenceResource;
use App\Entity\User;
use App\Enum\NotificationCategory;
use App\Repository\NotificationPreferenceRepositoryInterface;
use App\State\Account\NotificationPreferenceUpdateProcessor;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

#[AllowMockObjectsWithoutExpectations]
final class NotificationPreferenceUpdateProcessorTest extends TestCase
{
    private MockObject&Security $security;

    private MockObject&NotificationPreferenceRepositoryInterface $preferences;

    private NotificationPreferenceUpdateProcessor $processor;

    #[\Override]
    protected function setUp(): void
    {
        $this->security = $this->createMock(Security::class);
        $this->preferences = $this->createMock(NotificationPreferenceRepositoryInterface::class);

        $this->processor = new NotificationPreferenceUpdateProcessor($this->security, $this->preferences);
    }

    #[Test]
    public function itThrows409OnConcurrentInsertRaceCondition(): void
    {
        $this->security->method('getUser')->willReturn(new User('race@example.com'));

        // No preference yet: the processor takes the create path.
        $this->preferences->expects($this->once())->method('findOne')->willReturn(null);

        // A concurrent PUT for the same (user, category) inserted first: the flush
        // inside save() hits the unique constraint. The processor must translate it
        // to a 409, not let a 500 leak (same guard as DeviceTokenRegisterProcessor).
        $uniqueException = $this->getMockBuilder(UniqueConstraintViolationException::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->preferences->expects($this->once())->method('save')->willThrowException($uniqueException);

        $this->expectException(ConflictHttpException::class);
        $this->processor->process(
            new NotificationPreferenceResource(NotificationCategory::WEATHER_SAFETY, true),
            new Put(),
            ['category' => 'weatherSafety'],
        );
    }
}
