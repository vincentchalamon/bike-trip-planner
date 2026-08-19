<?php

declare(strict_types=1);

namespace App\Push;

interface PushSenderInterface
{
    /**
     * Sends one notification to each device token.
     *
     * @param list<string>          $tokens
     * @param array<string, string> $data
     *
     * @return list<string> the subset of tokens FCM rejected as permanently
     *                      invalid (UNREGISTERED / 404), to be pruned by the caller
     */
    public function send(array $tokens, string $title, string $body, array $data = []): array;
}
