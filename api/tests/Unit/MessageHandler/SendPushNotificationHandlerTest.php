<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\DeviceToken;
use App\Entity\User;
use App\Enum\DevicePlatform;
use App\Message\SendPushNotification;
use App\MessageHandler\SendPushNotificationHandler;
use App\Push\FcmSendException;
use App\Push\PushSenderInterface;
use App\Repository\DeviceTokenRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SendPushNotificationHandlerTest extends TestCase
{
    #[Test]
    public function resolvesUserTokensMergesExplicitOnesAndFoldsTheCategory(): void
    {
        $user = new User('rider@example.com');
        $repository = $this->createMock(DeviceTokenRepositoryInterface::class);
        $repository->expects($this->once())->method('findByUserId')->with($user->getId()->toRfc4122())->willReturn([
            new DeviceToken($user, 'user-token-1', DevicePlatform::ANDROID),
            new DeviceToken($user, 'user-token-2', DevicePlatform::IOS),
        ]);

        $sender = $this->createMock(PushSenderInterface::class);
        $sender->expects($this->once())
            ->method('send')
            ->with(
                ['explicit-token', 'user-token-1', 'user-token-2'],
                'Titre',
                'Corps',
                ['foo' => 'bar', 'category' => 'safety'],
            )
            ->willReturn([]);

        $repository->expects($this->once())->method('deleteByTokens')->with([]);

        new SendPushNotificationHandler($sender, $repository)(new SendPushNotification(
            title: 'Titre',
            body: 'Corps',
            userId: $user->getId()->toRfc4122(),
            tokens: ['explicit-token'],
            data: ['foo' => 'bar'],
            category: 'safety',
        ));
    }

    #[Test]
    public function prunesTheTokensTheSenderReportsInvalid(): void
    {
        $repository = $this->createMock(DeviceTokenRepositoryInterface::class);
        $sender = $this->createStub(PushSenderInterface::class);
        $sender->method('send')->willReturn(['dead-token']);

        $repository->expects($this->once())->method('deleteByTokens')->with(['dead-token']);

        new SendPushNotificationHandler($sender, $repository)(new SendPushNotification(
            title: 'T',
            body: 'B',
            tokens: ['live-token', 'dead-token'],
        ));
    }

    #[Test]
    public function prunesTheDeadTokensThenRethrowsWhenTheSenderFails(): void
    {
        // A real send failure rethrows for Messenger retry, but the dead tokens the
        // sender found in the same batch (carried on the exception) are pruned first
        // so they are not rediscovered on every retry (ADR-058).
        $repository = $this->createMock(DeviceTokenRepositoryInterface::class);
        $sender = $this->createStub(PushSenderInterface::class);
        $sender->method('send')->willThrowException(new FcmSendException('boom', ['dead-token']));

        $repository->expects($this->once())->method('deleteByTokens')->with(['dead-token']);

        $this->expectException(FcmSendException::class);

        new SendPushNotificationHandler($sender, $repository)(new SendPushNotification(
            title: 'T',
            body: 'B',
            tokens: ['live-token', 'dead-token'],
        ));
    }

    #[Test]
    public function doesNothingWhenThereIsNoRecipient(): void
    {
        $repository = $this->createMock(DeviceTokenRepositoryInterface::class);
        $sender = $this->createMock(PushSenderInterface::class);
        $sender->expects($this->never())->method('send');
        $repository->expects($this->never())->method('deleteByTokens');

        new SendPushNotificationHandler($sender, $repository)(new SendPushNotification(title: 'T', body: 'B'));
    }
}
