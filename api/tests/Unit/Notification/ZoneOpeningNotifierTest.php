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
    use NotificationTranslatorTrait;

    #[Test]
    public function pushesOnlyToOptedInUsersEachInTheirOwnLocale(): void
    {
        $preferences = $this->createMock(NotificationPreferenceRepositoryInterface::class);
        $preferences->expects($this->once())
            ->method('findEnabledUsers')
            ->with(NotificationCategory::ZONE_OPENING)
            ->willReturn([
                ['id' => 'user-fr', 'locale' => 'fr'],
                ['id' => 'user-en', 'locale' => 'en'],
            ]);

        $calls = [];
        $dispatcher = $this->createMock(NotificationDispatcherInterface::class);
        $dispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (string $userId, NotificationCategory $category, string $title, string $body) use (&$calls): bool {
                $calls[$userId] = ['title' => $title, 'body' => $body];

                return true;
            });

        $count = new ZoneOpeningNotifier($preferences, $dispatcher, $this->notificationTranslator())->notify('corse', 'Corse');

        self::assertSame(2, $count);
        self::assertSame('Nouvelle zone disponible', $calls['user-fr']['title']);
        self::assertStringContainsString('La zone Corse est maintenant couverte', $calls['user-fr']['body']);
        self::assertSame('New zone available', $calls['user-en']['title']);
        self::assertStringContainsString('The Corse zone is now covered', $calls['user-en']['body']);
    }

    #[Test]
    public function pushesToNobodyWhenNoUserOptedIn(): void
    {
        $preferences = $this->createStub(NotificationPreferenceRepositoryInterface::class);
        $preferences->method('findEnabledUsers')->willReturn([]);

        $dispatcher = $this->createMock(NotificationDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $count = new ZoneOpeningNotifier($preferences, $dispatcher, $this->notificationTranslator())->notify('corse', 'Corse');

        self::assertSame(0, $count);
    }
}
