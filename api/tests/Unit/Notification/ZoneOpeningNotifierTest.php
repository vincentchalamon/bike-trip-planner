<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Enum\NotificationCategory;
use App\Notification\NotificationDispatcherInterface;
use App\Notification\ZoneOpeningNotifier;
use App\Repository\NotificationPreferenceRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ZoneOpeningNotifierTest extends TestCase
{
    #[Test]
    public function pushesOnlyToUsersWhoOptedIntoZoneOpening(): void
    {
        $preferences = $this->createMock(NotificationPreferenceRepositoryInterface::class);
        $preferences->expects($this->once())
            ->method('findUserIdsEnabled')
            ->with(NotificationCategory::ZONE_OPENING)
            ->willReturn(['user-a', 'user-b']);

        $dispatcher = $this->createMock(NotificationDispatcherInterface::class);
        $dispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->with(
                $this->logicalOr('user-a', 'user-b'),
                NotificationCategory::ZONE_OPENING,
                'Nouvelle zone disponible',
                $this->stringContains('Corse'),
                ['zoneSlug' => 'corse'],
            )
            ->willReturn(true);

        $count = new ZoneOpeningNotifier($preferences, $dispatcher)->notify('corse', 'Corse');

        self::assertSame(2, $count);
    }

    #[Test]
    public function pushesToNobodyWhenNoUserOptedIn(): void
    {
        $preferences = $this->createStub(NotificationPreferenceRepositoryInterface::class);
        $preferences->method('findUserIdsEnabled')->willReturn([]);

        $dispatcher = $this->createMock(NotificationDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $count = new ZoneOpeningNotifier($preferences, $dispatcher)->notify('corse', 'Corse');

        self::assertSame(0, $count);
    }
}
