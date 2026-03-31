<?php

declare(strict_types=1);

namespace App\ApiResource;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for creating a trip share link.
 */
final class TripShareRequest
{
    /**
     * Optional expiration delay in hours. Null means no expiration.
     */
    #[Assert\Positive]
    public ?int $expiresInHours = null;
}
