<?php

declare(strict_types=1);

namespace App\ApiResource\Model;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A single turn of the stateless trip-brief conversation (ADR-045).
 *
 * Only `user` and `assistant` roles are accepted; `system` (or any other value)
 * is rejected with a 422 so a malicious client cannot inject a system turn.
 */
final class AiChatMessage
{
    public const string ROLE_USER = 'user';

    public const string ROLE_ASSISTANT = 'assistant';

    /**
     * @var list<string>
     */
    public const array ALLOWED_ROLES = [self::ROLE_USER, self::ROLE_ASSISTANT];

    /**
     * Per-message character ceiling (ADR-045). Exceeding it is rejected with a 422.
     */
    public const int MAX_CONTENT_LENGTH = 4000;

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: self::ALLOWED_ROLES)]
        #[ApiProperty(description: 'Conversation role, strictly "user" or "assistant".')]
        public string $role = '',
        #[Assert\NotBlank]
        #[Assert\Length(max: self::MAX_CONTENT_LENGTH)]
        #[ApiProperty(description: 'Message content.')]
        public string $content = '',
    ) {
    }
}
