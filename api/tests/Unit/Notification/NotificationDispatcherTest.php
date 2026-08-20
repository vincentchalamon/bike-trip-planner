<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Enum\NotificationCategory;
use App\Message\SendPushNotification;
use App\Notification\NotificationDispatcher;
use App\Repository\NotificationPreferenceRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class NotificationDispatcherTest extends TestCase
{
    private const string USER_ID = '0192a1b2-c3d4-7e5f-8a9b-000000000001';

    #[Test]
    public function dispatchesAPushWhenTheCategoryIsEnabled(): void
    {
        $preferences = $this->createStub(NotificationPreferenceRepositoryInterface::class);
        $preferences->method('isEnabled')->willReturn(true);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function (SendPushNotification $message): bool {
                self::assertSame(self::USER_ID, $message->userId);
                self::assertSame('analysisDone', $message->category);
                self::assertSame('Titre', $message->title);
                self::assertSame(['tripId' => 'abc'], $message->data);

                return true;
            }))
            ->willReturnCallback(static fn (SendPushNotification $message): Envelope => new Envelope($message));

        $dispatcher = new NotificationDispatcher($bus, $preferences);
        $sent = $dispatcher->dispatch(self::USER_ID, NotificationCategory::ANALYSIS_DONE, 'Titre', 'Corps', ['tripId' => 'abc']);

        self::assertTrue($sent);
    }

    #[Test]
    public function doesNotDispatchWhenTheCategoryIsDisabled(): void
    {
        $preferences = $this->createStub(NotificationPreferenceRepositoryInterface::class);
        $preferences->method('isEnabled')->willReturn(false);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $dispatcher = new NotificationDispatcher($bus, $preferences);
        $sent = $dispatcher->dispatch(self::USER_ID, NotificationCategory::ZONE_OPENING, 'Titre', 'Corps');

        self::assertFalse($sent);
    }
}
