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
        yield 'hotel' => ['hotel', 50.0, 120.0];
        yield 'wilderness_hut' => ['wilderness_hut', 0.0, 10.0];
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

    #[Test]
    public function estimatePriceAppliesBikepackerCapForCampSiteWithBackpackYes(): void
    {
        $result = $this->engine->estimatePrice('camp_site', ['backpack' => 'yes']);

        $this->assertSame(8.0, $result['min']);
        $this->assertSame(15.0, $result['max']);
        $this->assertFalse($result['isExact']);
    }

    #[Test]
    public function estimatePriceAppliesBikepackerCapForCampSiteWithTentsYes(): void
    {
        $result = $this->engine->estimatePrice('camp_site', ['tents' => 'yes']);

        $this->assertSame(8.0, $result['min']);
        $this->assertSame(15.0, $result['max']);
        $this->assertFalse($result['isExact']);
    }

    #[Test]
    public function estimatePriceDoesNotApplyBikepackerCapForCampSiteWithoutBikepackerTags(): void
    {
        $result = $this->engine->estimatePrice('camp_site', ['name' => 'Camping Standard']);

        $this->assertSame(8.0, $result['min']);
        $this->assertSame(25.0, $result['max']);
        $this->assertFalse($result['isExact']);
    }

    #[Test]
    public function estimatePriceReturnsFreeDonationRangeForWildernessHut(): void
    {
        $result = $this->engine->estimatePrice('wilderness_hut');

        $this->assertSame(0.0, $result['min']);
        $this->assertSame(10.0, $result['max']);
        $this->assertFalse($result['isExact']);
    }

    /**
     * @return iterable<string, array{?int, float}>
     */
    public static function hotelStarsProvider(): iterable
    {
        // Bracket floor lifted by 25% of the €50–€120 span per star above 2,
        // capped at 75% so a 5-star entry keeps a range (ADR-040 §45).
        yield 'unrated' => [null, 50.0];
        yield '1 star' => [1, 50.0];
        yield '2 stars' => [2, 50.0];
        yield '3 stars' => [3, 67.5];
        yield '4 stars' => [4, 85.0];
        yield '5 stars' => [5, 102.5];
    }

    #[DataProvider('hotelStarsProvider')]
    #[Test]
    public function estimatePriceLiftsTheBracketFloorWithTheStarRating(?int $stars, float $expectedMin): void
    {
        $result = $this->engine->estimatePrice('hotel', [], $stars);

        $this->assertSame($expectedMin, $result['min']);
        $this->assertSame(120.0, $result['max']);
        $this->assertFalse($result['isExact']);
    }

    #[Test]
    public function estimatePriceCombinesTheBikepackerCapWithTheStarRating(): void
    {
        // The lift applies within the capped span, not the full camp_site bracket.
        $result = $this->engine->estimatePrice('camp_site', ['backpack' => 'yes'], 4);

        $this->assertSame(11.5, $result['min']);
        $this->assertSame(15.0, $result['max']);
        $this->assertFalse($result['isExact']);
    }

    #[Test]
    public function estimatePricePricesAFeeFreeEntryAsFree(): void
    {
        $result = $this->engine->estimatePrice('camp_site', [], null, 'no');

        $this->assertSame(0.0, $result['min']);
        $this->assertSame(0.0, $result['max']);
        $this->assertTrue($result['isExact']);
    }

    #[Test]
    public function estimatePriceUsesANumericFeeColumnAsAnExactPrice(): void
    {
        // The provisioner fills `fee` with the OSM `fee` or `charge` tag.
        $result = $this->engine->estimatePrice('camp_site', [], null, '18 EUR');

        $this->assertSame(18.0, $result['min']);
        $this->assertSame(18.0, $result['max']);
        $this->assertTrue($result['isExact']);
    }

    #[Test]
    public function estimatePriceKeepsTheBracketForAFeeYesEntry(): void
    {
        $result = $this->engine->estimatePrice('camp_site', [], null, 'yes');

        $this->assertSame(8.0, $result['min']);
        $this->assertSame(25.0, $result['max']);
        $this->assertFalse($result['isExact']);
    }
}
