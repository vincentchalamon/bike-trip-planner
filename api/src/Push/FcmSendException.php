<?php

declare(strict_types=1);

namespace App\Push;

/**
 * A real FCM send/transport failure that must reach Messenger for retry, while
 * still carrying the UNREGISTERED tokens discovered in the same batch so the
 * caller can prune them before rethrowing (ADR-058: dead tokens are pruned once,
 * not rediscovered on every retry).
 */
final class FcmSendException extends \RuntimeException
{
    /**
     * @param list<string> $invalidTokens UNREGISTERED tokens to prune despite the failure
     */
    public function __construct(string $message, public readonly array $invalidTokens = [])
    {
        parent::__construct($message);
    }
}
