<?php

declare(strict_types=1);

namespace App\ApiResource\Model;

enum AlertActionKind: string
{
    case AUTO_FIX = 'auto_fix';
    case DETOUR = 'detour';
    case NAVIGATE = 'navigate';
    case DISMISS = 'dismiss';
}
