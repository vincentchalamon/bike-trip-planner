<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendPushNotification;
use App\Push\PushSenderInterface;
use App\Repository\DeviceTokenRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Delivers a push notification via FCM and prunes tokens FCM rejects (epic #1051).
 *
 * Recipients are the union of the message's explicit tokens and every token
 * registered by its userId. Tokens FCM reports as UNREGISTERED / 404 are deleted
 * so a dead device stops being targeted.
 */
#[AsMessageHandler]
final readonly class SendPushNotificationHandler
{
    public function __construct(
        private PushSenderInterface $pushSender,
        private DeviceTokenRepositoryInterface $deviceTokenRepository,
    ) {
    }

    public function __invoke(SendPushNotification $message): void
    {
        $tokens = $message->tokens;

        if (null !== $message->userId) {
            foreach ($this->deviceTokenRepository->findByUserId($message->userId) as $deviceToken) {
                $tokens[] = $deviceToken->getToken();
            }
        }

        $tokens = array_values(array_unique($tokens));
        if ([] === $tokens) {
            return;
        }

        $data = $message->data;
        if (null !== $message->category) {
            $data['category'] = $message->category;
        }

        $invalidTokens = $this->pushSender->send($tokens, $message->title, $message->body, $data);

        $this->deviceTokenRepository->deleteByTokens($invalidTokens);
    }
}
