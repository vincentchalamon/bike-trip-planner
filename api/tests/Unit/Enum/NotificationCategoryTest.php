<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\NotificationCategory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NotificationCategoryTest extends TestCase
{
    #[Test]
    public function safetyAndAnalysisAreOnByDefaultZoneOpeningIsOff(): void
    {
        self::assertTrue(NotificationCategory::WEATHER_SAFETY->defaultEnabled());
        self::assertTrue(NotificationCategory::ANALYSIS_DONE->defaultEnabled());
        self::assertFalse(NotificationCategory::ZONE_OPENING->defaultEnabled());
    }

    #[Test]
    public function wireValuesMatchTheClientContract(): void
    {
        self::assertSame('weatherSafety', NotificationCategory::WEATHER_SAFETY->value);
        self::assertSame('analysisDone', NotificationCategory::ANALYSIS_DONE->value);
        self::assertSame('zoneOpening', NotificationCategory::ZONE_OPENING->value);
    }
}
