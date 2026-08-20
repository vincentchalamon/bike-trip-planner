<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Server-pushed notification categories (#1124).
 *
 * Each category has a per-user opt-in stored in {@see \App\Entity\NotificationPreference}.
 * The default applies when the user has no explicit row: safety and analysis are
 * ON by default, opened-zone announcements are OFF (opt-in only).
 */
enum NotificationCategory: string
{
    case WEATHER_SAFETY = 'weatherSafety';
    case ANALYSIS_DONE = 'analysisDone';
    case ZONE_OPENING = 'zoneOpening';

    public function defaultEnabled(): bool
    {
        return match ($this) {
            self::WEATHER_SAFETY, self::ANALYSIS_DONE => true,
            self::ZONE_OPENING => false,
        };
    }
}
