<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Request to deliver a push notification through FCM (epic #1051, categories #1124).
 *
 * Recipients are resolved by the handler: pass a userId to target every device
 * the user has registered, and/or an explicit list of device tokens. The two
 * sets are merged. `data` is the FCM data payload (string map); `category` names
 * the notification category and is folded into `data` when set.
 */
final readonly class SendPushNotification
{
    /**
     * @param list<string>          $tokens
     * @param array<string, string> $data
     */
    public function __construct(
        public string $title,
        public string $body,
        public ?string $userId = null,
        public array $tokens = [],
        public array $data = [],
        public ?string $category = null,
    ) {
    }
}
