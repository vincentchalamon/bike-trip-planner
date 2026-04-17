<?php

declare(strict_types=1);

namespace App\Enum;

enum AccessRequestStatus: string
{
    case PENDING_VERIFICATION = 'pending_verification';
    case VERIFIED = 'verified';
}
