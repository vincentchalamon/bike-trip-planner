<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analyzer;

use App\Analyzer\StageAnalysisContext;
use App\ApiResource\TripRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StageAnalysisContextTest extends TestCase
{
    /**
     * An analysis run without a trip paces the rider as a trip with no pace set would.
     */
    #[Test]
    public function defaultsToThePaceOfATripThatSetsNone(): void
    {
        $context = new StageAnalysisContext();
        $trip = new TripRequest();

        self::assertSame($trip->departureHour, $context->departureHour);
        self::assertSame($trip->averageSpeed, $context->averageSpeed);
        self::assertNull($context->startDate);
        self::assertFalse($context->ebikeMode);
    }
}
