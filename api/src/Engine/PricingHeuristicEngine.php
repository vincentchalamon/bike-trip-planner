<?php

declare(strict_types=1);

namespace App\Engine;

// DIP: no interface — single consumer, single implementation. Extract when a second consumer arises.
final readonly class PricingHeuristicEngine implements EngineInterface
{
    /** @var array<string, array{min: float, max: float}> */
    private const array PRICE_BRACKETS = [
        'camp_site' => ['min' => 8.0, 'max' => 25.0],
        'hostel' => ['min' => 20.0, 'max' => 35.0],
        'alpine_hut' => ['min' => 25.0, 'max' => 45.0],
        'chalet' => ['min' => 30.0, 'max' => 70.0],
        'guest_house' => ['min' => 40.0, 'max' => 80.0],
        'motel' => ['min' => 45.0, 'max' => 90.0],
        'hotel' => ['min' => 50.0, 'max' => 120.0],
    ];

    /**
     * Returns estimated price range for an accommodation type.
     * If an exact charge tag is provided, returns it as both min and max.
     *
     * @param array<string, string> $osmTags OSM tags for the accommodation element
     *
     * @return array{min: float, max: float, isExact: bool}
     */
    public function estimatePrice(string $accommodationType, array $osmTags = []): array
    {
        // Exact price from OSM `charge` tag (e.g. "15 EUR")
        if (isset($osmTags['charge'])) {
            $price = $this->parseChargeTag($osmTags['charge']);
            if (null !== $price) {
                return ['min' => $price, 'max' => $price, 'isExact' => true];
            }
        }

        $bracket = self::PRICE_BRACKETS[$accommodationType] ?? self::PRICE_BRACKETS['hotel'];

        return ['min' => $bracket['min'], 'max' => $bracket['max'], 'isExact' => false];
    }

    private function parseChargeTag(string $charge): ?float
    {
        // Extract numeric value from strings like "15 EUR", "15€", "15.50"
        if (preg_match('/(\d+(?:[.,]\d+)?)/', $charge, $matches)) {
            return (float) str_replace(',', '.', $matches[1]);
        }

        return null;
    }
}
