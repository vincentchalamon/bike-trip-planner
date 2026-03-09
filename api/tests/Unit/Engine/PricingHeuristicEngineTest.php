<?php

declare(strict_types=1);

namespace App\Tests\Unit\Engine;

use App\Engine\PricingHeuristicEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PricingHeuristicEngineTest extends TestCase
{
    private PricingHeuristicEngine $engine;

    #[\Override]
    protected function setUp(): void
    {
        $this->engine = new PricingHeuristicEngine();
    }

    /**
     * @return iterable<string, array{string, float, float}>
     */
    public static function priceBracketProvider(): iterable
    {
        yield 'camp_site' => ['camp_site', 8.0, 25.0];
        yield 'hostel' => ['hostel', 20.0, 35.0];
        yield 'alpine_hut' => ['alpine_hut', 25.0, 45.0];
        yield 'chalet' => ['chalet', 30.0, 70.0];
        yield 'guest_house' => ['guest_house', 40.0, 80.0];
        yield 'motel' => ['motel', 45.0, 90.0];
        yield 'hotel' => ['hotel', 50.0, 120.0];
    }

    #[DataProvider('priceBracketProvider')]
    #[Test]
    public function estimatePriceReturnsCorrectBracket(string $type, float $expectedMin, float $expectedMax): void
    {
        $result = $this->engine->estimatePrice($type);

        $this->assertSame($expectedMin, $result['min']);
        $this->assertSame($expectedMax, $result['max']);
        $this->assertFalse($result['isExact']);
    }

    #[Test]
    public function estimatePriceFallsBackToHotelForUnknownType(): void
    {
        $result = $this->engine->estimatePrice('unknown_type');

        $this->assertSame(50.0, $result['min']);
        $this->assertSame(120.0, $result['max']);
        $this->assertFalse($result['isExact']);
    }

    #[Test]
    public function estimatePriceUsesExactChargeTag(): void
    {
        $result = $this->engine->estimatePrice('camp_site', ['charge' => '15 EUR']);

        $this->assertSame(15.0, $result['min']);
        $this->assertSame(15.0, $result['max']);
        $this->assertTrue($result['isExact']);
    }

    #[Test]
    public function estimatePriceUsesExactChargeTagWithEuroSymbol(): void
    {
        $result = $this->engine->estimatePrice('hostel', ['charge' => '25€']);

        $this->assertSame(25.0, $result['min']);
        $this->assertSame(25.0, $result['max']);
        $this->assertTrue($result['isExact']);
    }

    #[Test]
    public function estimatePriceUsesExactChargeTagWithDecimals(): void
    {
        $result = $this->engine->estimatePrice('hotel', ['charge' => '15.50']);

        $this->assertSame(15.5, $result['min']);
        $this->assertSame(15.5, $result['max']);
        $this->assertTrue($result['isExact']);
    }

    #[Test]
    public function estimatePriceUsesChargeTagWithCommaDecimal(): void
    {
        $result = $this->engine->estimatePrice('hotel', ['charge' => '15,50 EUR']);

        $this->assertSame(15.5, $result['min']);
        $this->assertSame(15.5, $result['max']);
        $this->assertTrue($result['isExact']);
    }

    #[Test]
    public function estimatePriceFallsToBracketOnInvalidChargeTag(): void
    {
        $result = $this->engine->estimatePrice('camp_site', ['charge' => 'free']);

        $this->assertSame(8.0, $result['min']);
        $this->assertSame(25.0, $result['max']);
        $this->assertFalse($result['isExact']);
    }

    #[Test]
    public function estimatePriceIgnoresUnrelatedOsmTags(): void
    {
        $result = $this->engine->estimatePrice('hostel', ['name' => 'My Hostel', 'tourism' => 'hostel']);

        $this->assertSame(20.0, $result['min']);
        $this->assertSame(35.0, $result['max']);
        $this->assertFalse($result['isExact']);
    }
}
